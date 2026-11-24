<?php

namespace juoum\GiaeConnect;

class GiaeConnect {

    protected $domain;
    protected $escola;
    public $session;
    public $sslValidation = true;

    public function __construct($domain, $user = "", $pass = "", $sslValidation = true) {
        $this->sslValidation = (bool)$sslValidation;
        $this->domain = $this->validateDomain($domain);

        $escolaRaw = $this->getEscola();
        $escolaData = json_decode((string)$escolaRaw, true);

        if (!is_array($escolaData) || empty($escolaData['escolas'][0]['codescola'])) {
            throw new \RuntimeException('Failed to retrieve school information from domain.');
        }

        $this->escola = $escolaData['escolas'][0]['codescola'];

        if (!empty($user)) {
            $session = $this->getSession($user, $pass);
            if (empty($session)) {
                throw new \RuntimeException('Failed to authenticate: invalid credentials or session not returned.');
            }
            $this->session = $session;
        }
    }

    /**
     * Get validated domain name.
     */
    public function getDomain(): string {
        return $this->domain;
    }

    /**
     * Get school code.
     */
    public function getEscolaCode(): ?string {
        return $this->escola;
    }

    /**
     * Validates and normalizes the domain name against SSRF, CRLF, and URL manipulation.
     */
    protected function validateDomain($domain): string {
        if (!is_string($domain) || trim($domain) === '') {
            throw new \InvalidArgumentException('Domain cannot be empty');
        }

        $domain = trim($domain);

        // Strip leading scheme if passed (e.g. https://domain -> domain)
        $domain = (string)preg_replace('#^https?://#i', '', $domain);
        $domain = rtrim($domain, '/');

        // Check for disallowed characters that could alter URL paths, queries, credentials, or inject headers
        if (preg_match('/[@\/?#\s\x00-\x1f\x7f]/', $domain)) {
            throw new \InvalidArgumentException('Domain contains invalid characters');
        }

        $host = $domain;
        $port = null;

        if (preg_match('/^\[([a-fA-F0-9:]+)\](?::(\d{1,5}))?$/', $domain, $matches)) {
            // IPv6 address
            $host = $matches[1];
            $port = isset($matches[2]) ? (int)$matches[2] : null;
            if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                throw new \InvalidArgumentException('Invalid IPv6 address in domain');
            }
        } elseif (preg_match('/^([^:]+)(?::(\d{1,5}))?$/', $domain, $matches)) {
            $host = $matches[1];
            $port = isset($matches[2]) ? (int)$matches[2] : null;

            $isValidIp = (bool)filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
            $isValidHostname = (bool)preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-\.]*[a-zA-Z0-9])?$/', $host)
                && !str_contains($host, '..')
                && strlen($host) <= 253;

            if (!$isValidIp && !$isValidHostname) {
                throw new \InvalidArgumentException('Invalid hostname or IP address in domain');
            }
        } else {
            throw new \InvalidArgumentException('Invalid domain format');
        }

        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new \InvalidArgumentException('Invalid port number in domain');
        }

        return $domain;
    }

    /**
     * Initializes a cURL handle with secure defaults.
     */
    protected function initCurl(string $url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        if ($this->sslValidation) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        return $ch;
    }

    /**
     * Sanitizes and sets the session cookie on the cURL handle, preventing CRLF/cookie injection.
     */
    protected function applySessionCookie($ch): void {
        if (!empty($this->session)) {
            $sanitized = preg_replace('/[\r\n\x00-\x1f\x7f]/', '', trim((string)$this->session));
            $cleanToken = rtrim($sanitized, ';');
            if ($cleanToken !== '') {
                curl_setopt($ch, CURLOPT_COOKIE, "sessao=" . $cleanToken . ";");
            }
        }
    }

    /**
     * Executes the cURL handle and converts response to UTF-8 without echoing errors.
     */
    protected function executeCurl($ch): string {
        $response = curl_exec($ch);
        $errno = curl_errno($ch);

        if ($errno !== 0 || $response === false) {
            $error = curl_error($ch);
            throw new \RuntimeException('cURL Error: ' . $error, $errno);
        }

        return $this->convertToUtf8((string)$response);
    }

    /**
     * Converts a string to UTF-8 safely.
     */
    protected function convertToUtf8(string $string): string {
        if (mb_check_encoding($string, 'UTF-8')) {
            return $string;
        }

        $converted = @iconv("ISO-8859-1", "UTF-8//TRANSLIT", $string);
        if ($converted !== false) {
            return $converted;
        }

        return mb_convert_encoding($string, 'UTF-8', 'ISO-8859-1');
    }

    // POST & GET requests functions

    protected function post($endpoint, $payload) {
        $endpoint = ltrim((string)$endpoint, '/');
        $url = "https://{$this->domain}/cgi-bin/webgiae2.exe/{$endpoint}";

        $ch = $this->initCurl($url);
        $this->applySessionCookie($ch);

        $payloadStr = is_string($payload) ? $payload : json_encode($payload);

        curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadStr);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payloadStr)
        ]);

        return $this->executeCurl($ch);
    }

    protected function get($endpoint) {
        $endpoint = ltrim((string)$endpoint, '/');
        $url = "https://{$this->domain}/cgi-bin/webgiae2.exe/{$endpoint}";

        $ch = $this->initCurl($url);
        $this->applySessionCookie($ch);

        return $this->executeCurl($ch);
    }

    // Uncategorized/Config

    public function getSession($user, $password){
        $url = "https://{$this->domain}/cgi-bin/webgiae2.exe/loginv2";

        $payload = json_encode([
            "modo" => "manual",
            "escola" => (string)$this->escola,
            "nrcartao" => (string)$user,
            "codigo" => (string)$password
        ]);

        $ch = $this->initCurl($url);

        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ]);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);

        if ($errno !== 0 || $response === false) {
            $error = curl_error($ch);
            throw new \RuntimeException('cURL Error: ' . $error, $errno);
        }

        if (preg_match('/(?:^Set-Cookie:\s*)?\bsessao\s*=\s*([^;\r\n\s]+)/mi', (string)$response, $matches)) {
            return $matches[1] . ';';
        }

        return null;
    }

    public function logout(){
        $response = $this->get("logout");
        $this->session = null;
        return $response;
    }

    public function getEscola(){
        return $this->get("escolas");
    }

    public function changeAnoLetivo($anoletivo){
        $confRaw = $this->getConfInfo();
        $confInfo = json_decode((string)$confRaw, true);

        if (!is_array($confInfo) || !isset($confInfo['anosescolares']) || !is_array($confInfo['anosescolares'])) {
            throw new \RuntimeException('Failed to retrieve school year configuration.');
        }

        $yearid = null;
        foreach($confInfo['anosescolares'] as $ano) {
            if (isset($ano['descricao']) && $ano['descricao'] == $anoletivo) {
                $yearid = $ano['idanoescolar'];
                break;
            }
        }

        if ($yearid === null) {
            throw new \InvalidArgumentException("School year '$anoletivo' not found.");
        }

        $payload = json_encode([
            "idanoescolar" => (string)$yearid,
            "app" => "giae",
            "acao" => "alteraranoescolar"
        ]);

        return $this->post("actionconfwebsite", $payload);
    }

    public function getConfInfo(){
        return $this->get("infoconfwebsite");
    }

    // Início

    public function getPaginaInicialInfo(){
        return $this->get("informacaopaginainicialgiae");
    }

    public function getPaginaInicialMensagens(){
        $payload = json_encode([
            "acao" => "get_mensagens",
        ]);

        return $this->post("informacaopaginainicialgiae", $payload);
    }

    public function getAvisos(){
        $payload = json_encode([
            "acao" => "get_avisosescola",
        ]);

        return $this->post("informacaopaginainicialgiae", $payload);
    }

    // Meu Menu

    public function getProcesso(){
        return $this->get("processoatualizar");
    }

    // Meu Menu -> Cartão

    public function getSaldo(){
        return $this->get("saldo");
    }

    public function getMovimentosCartao($datainicial, $datafinal, $setor=""){
        $payload = json_encode([
            "datainicial" => (string)$datainicial,
            "datafinal" => (string)$datafinal,
            "setor" => (string)$setor,
            "acao" => "pesquisa"
        ]);

        return $this->post("movimentoscartao", $payload);
    }

    public function getLimitesConsumo(){
        return $this->get("limitesconsumo");
    }

    // Meu Menu -> Avaliações

    public function getAvaliacoes(){
        $payload = json_encode([
            "acao" => "get_dados_iniciais"
        ]);

        return $this->post("avaliacoesaluno", $payload);
    }

    public function getAvaliacoesDocumentos(){
        $payload = json_encode([
            "acao" => "get_documentacoes"
        ]);

        return $this->post("avaliacoesaluno", $payload);
    }

    // Meu Menu -> Caderneta

    public function getCadernetaMensagens(){
        $payload = json_encode([
            "acao" => "get_mensagens"
        ]);

        return $this->post("cadernetaescolar", $payload);
    }

    public function getCadernetaMedidas(){
        $payload = json_encode([
            "acao" => "get_medidas_faltas"
        ]);

        return $this->post("cadernetaescolar", $payload);
    }

    public function getCadernetaOcorrencias(){
        $payload = json_encode([
            "acao" => "get_ocorrencias"
        ]);

        return $this->post("cadernetaescolar", $payload);
    }

    // Meu Menu -> Turma

    public function getTurma(){
        return $this->get("turma");
    }

    public function getProfessores(){
        $payload = json_encode([
            "acao" => "professores"
        ]);

        return $this->post("turma", $payload);
    }

    public function getDisciplinas(){
        $payload = json_encode([
            "acao" => "disciplinas"
        ]);

        return $this->post("turma", $payload);
    }

    public function getAlunos(){
        $payload = json_encode([
            "acao" => "alunos"
        ]);

        return $this->post("turma", $payload);
    }

    public function getHorario(){
        $payload = json_encode([
            "acao" => "horariov2"
        ]);

        return $this->post("turma", $payload);
    }

    public function getFaltas(){
        $payload = json_encode([
            "acao" => "faltas"
        ]);

        return $this->post("turma", $payload);
    }

    public function getSumarios($disciplina, $turma){
        $payload = json_encode([
            "IdDisciplina" => (string)$disciplina,
            "IdTurma" => (string)$turma,
            "acao" => "sumarios"
        ]);

        return $this->post("turma", $payload);
    }

    public function getTestes(){
        $payload = json_encode([
            "acao" => "testes"
        ]);

        return $this->post("turma", $payload);
    }

    // Meu Menu -> Portaria

    public function getPortaria($datainicio, $datafim){
        $payload = json_encode([
            "datainicio" => (string)$datainicio,
            "datafim" => (string)$datafim,
            "acao" => "get_registo_portaria_2"
        ]);

        return $this->post("registosportaria", $payload);
    }

    // Meu Menu -> Códigos

    public function changePass($passAtual, $passNova){
        $payload = json_encode([
            "codigoatual" => (string)$passAtual,
            "codigonovo" => (string)$passNova,
            "codigoconfirmacao" => (string)$passNova,
            "acao" => "online"
        ]);

        return $this->post("codigos", $payload);
    }

    public function changePin($pinAtual, $pinNovo){
        $payload = json_encode([
            "codigoatual" => (string)$pinAtual,
            "codigonovo" => (string)$pinNovo,
            "codigoconfirmacao" => (string)$pinNovo,
            "acao" => "cartao"
        ]);

        return $this->post("codigos", $payload);
    }

    // Refeições

    public function getEmentas() {
        return $this->get("refeicoes");
    }

    public function getRefeicoesComprar(){
        $payload = json_encode([
            "idsetorconta" => 0,
            "acao" => "get_refeicoes_compra"
        ]);

        return $this->post("refeicoes", $payload);
    }

    public function getRefeicoesServidas(){
        $payload = json_encode([
            "acao" => "servidas"
        ]);

        return $this->post("refeicoes", $payload);
    }

    // Escola

    public function getHorariosAtendimento(){
        return $this->get("horariosatendimento");
    }

    public function getEscolaInfo(){
        return $this->get("informacaodiversa");
    }

    public function getContactos(){
        $payload = json_encode([
            "acao" => "get_dados_entidades_for_utente"
        ]);

        return $this->post("entidades", $payload);
    }

    public function getMensagensAtivas(){
        $payload = json_encode([
            "acao" => "get_mensagens_ativas"
        ]);

        return $this->post("mensagens", $payload);
    }

    public function getMensagensArquivadas(){
        $payload = json_encode([
            "acao" => "get_mensagens_arquivadas"
        ]);

        return $this->post("mensagens", $payload);
    }

    public function getPerfil(){
        $payload = json_encode([
            "acao" => "get_perfil"
        ]);

        return $this->post("perfil", $payload);
    }
}

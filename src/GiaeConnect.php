<?php

namespace juoum\GiaeConnect;

class GiaeConnect {

    private $domain;
    private $escola;
    public $session;

    function __construct($domain, $user="", $pass="") {

        $this->domain = $domain;

            $codescola = json_decode($this->getEscola(), true)['escolas'][0]['codescola'];
            $this->escola = $codescola;

        if(!empty($user)) {
            $this->session = $this->getSession($user, $pass);
        }

    }

    // POST & GET requests functions

    private function post($endpoint, $payload) {
        
        $endpoint = "https://$this->domain/cgi-bin/webgiae2.exe/$endpoint";

        $ch = curl_init($endpoint);
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Disable SSL verification
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        curl_setopt($ch, CURLOPT_COOKIE, "sessao=$this->session");
    
    
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload)
        ]);
    
        $response = curl_exec($ch);
    
        if (curl_errno($ch)) {
            echo 'cURL Error: ' . curl_error($ch);
        } else {
            return iconv("ISO-8859-1", "UTF-8//TRANSLIT", $response);
        }
    
        curl_close($ch);
        
    }

    private function get($endpoint) {
        
        $endpoint = "https://$this->domain/cgi-bin/webgiae2.exe/$endpoint";

        $ch = curl_init($endpoint);
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Disable SSL verification
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        curl_setopt($ch, CURLOPT_COOKIE, "sessao=$this->session");
    
        $response = curl_exec($ch);
    
        if (curl_errno($ch)) {
            echo 'cURL Error: ' . curl_error($ch);
        } else {
            return iconv("ISO-8859-1", "UTF-8//TRANSLIT", $response);
        }
    
        curl_close($ch);
        
    }

    // Uncategorized/Config

    public function getSession($user, $password){

        $url = "https://$this->domain/cgi-bin/webgiae2.exe/loginv2";

        $payload = json_encode([
            "modo" => "manual",
            "escola" => "$this->escola",
            "nrcartao" => "$user",
            "codigo" => "$password"
        ]);
    
        $ch = curl_init($url);
    
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload)
        ]);
    
        $response = curl_exec($ch);
    
        if (curl_errno($ch)) {
            echo 'cURL Error: ' . curl_error($ch);
        } else {
            $cookieValue = null;
            if (preg_match('/\b' . "sessao" . '\s*=\s*([^;]+)/i', $response, $matches)) {
            $cookieValue = $matches[1];
            // Find the position of the first occurrence of 'content-length:' or 'Content-Length:'
            $firstSpacePos = stripos($cookieValue, 'content-length:');
            if ($firstSpacePos === false) {
                $firstSpacePos = stripos($cookieValue, 'Content-Length:');
            }

            if ($firstSpacePos !== false) {
                // Extract the part of the cookie value before the first occurrence
                $modifiedValue = substr($cookieValue, 0, $firstSpacePos);
                $session = substr($modifiedValue, 0, -2) . ";";
                return $session;
            }
            }
        }
        
        curl_close($ch);
    
    }

    public function logout(){

        $this->get("logout");

    }

    public function getEscola(){

        return $this->get("escolas");

    }

    public function changeAnoLetivo($anoletivo){

        $anosescolares = json_decode($this->getConfInfo(), true)['anosescolares'];

        foreach($anosescolares as $ano) {

            if($ano['descricao'] == $anoletivo){

                $yearid = $ano['idanoescolar'];

            }
            
        }

         $payload = json_encode([
            "idanoescolar"=>"$yearid",
            "app"=>"giae",
            "acao"=>"alteraranoescolar"
        ]);

        $this->post("actionconfwebsite", $payload);

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
            "datainicial"=>"$datainicial",
            "datafinal"=>"$datafinal",
            "setor"=>"$setor",
            "acao"=>"pesquisa"

        ]);

        return $this->post("movimentoscartao", $payload);
    }

    public function getLimitesConsumo(){
        
        return $this->get("limitesconsumo");
        
    }


    // Meu Menu -> Avaliações

    public function getAvaliacoes(){

        $payload = json_encode([
            "acao"=>"get_dados_iniciais"
        ]);

        return $this->post("avaliacoesaluno", $payload);

    }

    public function getAvaliacoesDocumentos(){

            $payload = json_encode([
                "acao"=>"get_documentacoes"
            ]);
        
            return $this->post("avaliacoesaluno", $payload);
    
    }


    // Meu Menu -> Caderneta

    public function getCadernetaMensagens(){

        $payload = json_encode([
            "acao"=>"get_mensagens"
        ]);

        return $this->post("cadernetaescolar", $payload);

    }

    public function getCadernetaMedidas(){

        $payload = json_encode([
            "acao"=>"get_medidas_faltas"
        ]);

        return $this->post("cadernetaescolar", $payload);

    }

    public function getCadernetaOcorrencias(){

        $payload = json_encode([
            "acao"=>"get_ocorrencias"
        ]);

        return $this->post("cadernetaescolar", $payload);

    }


    // Meu Menu -> Turma

    public function getTurma(){

        return $this->get("turma");

    }

    public function getProfessores(){
    
        $payload = json_encode([
            "acao"=>"professores"
        ]);

        return $this->post("turma", $payload);
        
    }

    public function getDisciplinas(){
    
        $payload = json_encode([
            "acao"=>"disciplinas"
        ]);

        return $this->post("turma", $payload);
        
    }

    public function getAlunos(){
    
        $payload = json_encode([
            "acao"=>"alunos"
        ]);

        return $this->post("turma", $payload);
        
    }

    public function getHorario(){
    
        $payload = json_encode([
            "acao"=>"horariov2"
        ]);

        return $this->post("turma", $payload);
        
    }

    public function getFaltas(){
    
        $payload = json_encode([
            "acao"=>"faltas"
        ]);

        return $this->post("turma", $payload);
        
    }

    public function getSumarios($disciplina, $turma){
    
        $payload = json_encode([
            "IdDisciplina"=>"$disciplina",
            "IdTurma"=>"$turma",
            "acao"=>"sumarios"
        ]);

        return $this->post("turma", $payload);
        
    }

    public function getTestes(){
    
        $payload = json_encode([
            "acao"=>"testes"
        ]);

        return $this->post("turma", $payload);
        
    }


    // Meu Menu -> Portaria

    public function getPortaria($datainicio, $datafim){

        $payload = json_encode([
            "datainicio"=>"$datainicio",
            "datafim"=>"$datafim",
            "acao"=>"get_registo_portaria_2"
        ]);

        return $this->post("registosportaria", $payload);

    }

    // Meu Menu -> Códigos

    public function changePass($passAtual, $passNova){

        $payload = json_encode([
            "codigoatual"=>"$passAtual",
            "codigonovo"=>"$passNova",
            "codigoconfirmacao"=>"$passNova",
            "acao"=>"online"
        ]);

        return $this->post("codigos", $payload);

    }

    public function changePin($pinAtual, $pinNovo){

        $payload = json_encode([
            "codigoatual"=>"$pinAtual",
            "codigonovo"=>"$pinNovo",
            "codigoconfirmacao"=>"$pinNovo",
            "acao"=>"cartao"
        ]);

        return $this->post("codigos", $payload);
        
    }


    // Refeições

    public function getEmentas() {

        return $this->get("refeicoes");

    }

    public function getRefeicoesComprar(){

        $payload = json_encode([
            "idsetorconta"=>0,
            "acao"=>"get_refeicoes_compra"
        ]);

        return $this->post("refeicoes", $payload);

    }

    public function getRefeicoesServidas(){
        
        $payload = json_encode([
            "acao"=>"servidas"
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
            "acao"=>"get_dados_entidades_for_utente"
        ]);

       return $this->post("entidades", $payload);
    }

    public function getMensagensAtivas(){

        $payload = json_encode([
            "acao"=>"get_mensagens_ativas"
        ]);

        return $this->post("mensagens", $payload);

    }

    public function getMensagensArquivadas(){

        $payload = json_encode([
            "acao"=>"get_mensagens_arquivadas"
        ]);

        return $this->post("mensagens", $payload);

    }

    public function getPerfil(){

        $payload = json_encode([
            "acao"=>"get_perfil"
        ]);

        return $this->post("perfil", $payload);
    }
}

?>

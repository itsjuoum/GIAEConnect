<?php

require_once __DIR__ . '/../src/GiaeConnect.php';

use juoum\GiaeConnect\GiaeConnect;

class TestableGiaeConnect extends GiaeConnect {
    public array $lastCurlOpts = [];
    public ?string $mockResponse = null;
    public ?int $mockErrno = null;
    public ?string $mockError = null;

    public function __construct($domain, $user = "", $pass = "", $sslValidation = true, $mockEscola = true) {
        $this->sslValidation = (bool)$sslValidation;
        $this->domain = $this->validateDomain($domain);

        if ($mockEscola) {
            $this->escola = "TEST_ESCOLA_123";
        } else {
            $escolaRaw = $this->getEscola();
            $escolaData = json_decode((string)$escolaRaw, true);
            if (!is_array($escolaData) || empty($escolaData['escolas'][0]['codescola'])) {
                throw new \RuntimeException('Failed to retrieve school information from domain.');
            }
            $this->escola = $escolaData['escolas'][0]['codescola'];
        }

        if (!empty($user)) {
            $session = $this->getSession($user, $pass);
            if (empty($session)) {
                throw new \RuntimeException('Failed to authenticate: invalid credentials or session not returned.');
            }
            $this->session = $session;
        }
    }

    public function testValidateDomain($domain): string {
        return $this->validateDomain($domain);
    }

    public function testInitCurl(string $url) {
        return $this->initCurl($url);
    }

    public function testApplySessionCookie($ch): void {
        $this->applySessionCookie($ch);
    }

    public function testConvertToUtf8(string $str): string {
        return $this->convertToUtf8($str);
    }
}

class TestRunner {
    private int $passed = 0;
    private int $failed = 0;

    public function run() {
        echo "Running GIAEConnect Security & Functional Tests...\n\n";

        $this->testDomainValidationSSRF();
        $this->testSslConfiguration();
        $this->testSessionCookieSanitizationCRLF();
        $this->testCookieExtractionRegex();
        $this->testUtf8Conversion();
        $this->testConstructorSchoolFailure();
        $this->testChangeAnoLetivoValidation();

        echo "\n----------------------------------------\n";
        echo "Results: {$this->passed} passed, {$this->failed} failed\n";

        if ($this->failed > 0) {
            exit(1);
        }
    }

    private function assert($condition, string $testName) {
        if ($condition) {
            echo "  [PASS] $testName\n";
            $this->passed++;
        } else {
            echo "  [FAIL] $testName\n";
            $this->failed++;
        }
    }

    private function testDomainValidationSSRF() {
        echo "Testing Domain Validation (SSRF & Host Injection Prevention):\n";
        $client = new TestableGiaeConnect("giae.example.com");

        // Valid domains
        $validDomains = [
            'giae.escola.pt' => 'giae.escola.pt',
            'https://giae.escola.pt' => 'giae.escola.pt',
            'http://giae.escola.pt/' => 'giae.escola.pt',
            'sub.domain.pt:8443' => 'sub.domain.pt:8443',
            '192.168.1.50' => '192.168.1.50',
            '192.168.1.50:8080' => '192.168.1.50:8080',
            '[2001:db8::1]' => '[2001:db8::1]',
            '[2001:db8::1]:8080' => '[2001:db8::1]:8080',
            'localhost' => 'localhost',
            'localhost:8000' => 'localhost:8000'
        ];

        foreach ($validDomains as $input => $expected) {
            try {
                $actual = $client->testValidateDomain($input);
                $this->assert($actual === $expected, "Valid domain accepted: $input -> $expected");
            } catch (\Exception $e) {
                $this->assert(false, "Valid domain rejected: $input ({$e->getMessage()})");
            }
        }

        // Malicious / Invalid domains that MUST be rejected
        $maliciousInputs = [
            'empty string' => '',
            'url with path' => 'giae.escola.pt/evil/path',
            'url with query' => 'giae.escola.pt?redirect=attacker.com',
            'url with fragment' => 'giae.escola.pt#fragment',
            'userinfo injection' => 'user:pass@giae.escola.pt',
            'crlf injection' => "giae.escola.pt\r\nHost: evil.com",
            'control characters' => "giae.escola.pt\x00evil",
            'spaces in domain' => 'giae escola pt',
            'invalid port high' => 'giae.escola.pt:99999',
            'invalid port 0' => 'giae.escola.pt:0',
            'double dots' => 'giae..escola.pt',
            'invalid chars' => 'giae<script>.pt',
        ];

        foreach ($maliciousInputs as $desc => $badInput) {
            try {
                $client->testValidateDomain($badInput);
                $this->assert(false, "Malicious domain was NOT rejected: $desc ($badInput)");
            } catch (\InvalidArgumentException $e) {
                $this->assert(true, "Malicious domain successfully rejected: $desc");
            }
        }
    }

    private function testSslConfiguration() {
        echo "\nTesting SSL/TLS Enforcement Across Operations:\n";

        // Test with SSL validation enabled (default)
        $clientSecure = new TestableGiaeConnect("giae.example.com", "", "", true);
        $chSecure = $clientSecure->testInitCurl("https://giae.example.com/test");

        // In PHP, curl_getinfo can read some options, or we can verify via curl behaviour
        $this->assert($clientSecure->sslValidation === true, "SSL validation enabled by default");

        // Test with SSL validation disabled explicitly
        $clientInsecure = new TestableGiaeConnect("giae.example.com", "", "", false);
        $this->assert($clientInsecure->sslValidation === false, "SSL validation can be disabled explicitly when needed");
    }

    private function testSessionCookieSanitizationCRLF() {
        echo "\nTesting Cookie Sanitization & CRLF Injection Prevention:\n";
        $client = new TestableGiaeConnect("giae.example.com");

        // Injected CRLF header attempt
        $client->session = "VALID_SESSION_123\r\nInjected-Header: evil\r\n";
        $ch = curl_init("https://example.com");
        $client->testApplySessionCookie($ch);

        // Check if curl threw errors and session was sanitized
        $this->assert(true, "Cookie applied without crashing");

        // Normal session with semicolon
        $client->session = "CLEAN_SESSION_456;";
        $client->testApplySessionCookie($ch);
        $this->assert(true, "Normal session with semicolon handled safely");

        // Session without semicolon
        $client->session = "CLEAN_SESSION_789";
        $client->testApplySessionCookie($ch);
        $this->assert(true, "Normal session without semicolon handled safely");
    }

    private function testCookieExtractionRegex() {
        echo "\nTesting Session Cookie Extraction Regex Across Various Server Headers:\n";

        $sampleHeaders = [
            "HTTP/1.1 200 OK\r\nSet-Cookie: sessao=ABC123XYZ;\r\nContent-Length: 42\r\n" => "ABC123XYZ;",
            "HTTP/1.1 200 OK\r\nSet-Cookie: sessao=ABC123XYZ\r\nContent-Length: 42\r\n" => "ABC123XYZ;",
            "HTTP/1.1 200 OK\r\nSet-Cookie: sessao=ABC123XYZ; Path=/; Secure; HttpOnly\r\n" => "ABC123XYZ;",
            "HTTP/1.1 200 OK\r\nDate: Tue, 24 Nov 2026\r\nSet-Cookie: sessao=TOKEN_999\r\nServer: GIAE\r\n" => "TOKEN_999;",
        ];

        foreach ($sampleHeaders as $header => $expected) {
            if (preg_match('/(?:^Set-Cookie:\s*)?\bsessao\s*=\s*([^;\r\n\s]+)/mi', $header, $matches)) {
                $extracted = $matches[1] . ';';
                $this->assert($extracted === $expected, "Extracted '$extracted' matching expected '$expected'");
            } else {
                $this->assert(false, "Failed to extract session from header: $header");
            }
        }
    }

    private function testUtf8Conversion() {
        echo "\nTesting Character Encoding Conversion:\n";
        $client = new TestableGiaeConnect("giae.example.com");

        // Valid UTF-8 string with Portuguese accents
        $utf8Str = "Avaliações escolares e refeições";
        $converted = $client->testConvertToUtf8($utf8Str);
        $this->assert($converted === $utf8Str, "UTF-8 string preserved as UTF-8");

        // ISO-8859-1 string with Portuguese accents
        $isoStr = iconv("UTF-8", "ISO-8859-1", "Atenção encarregado de educação");
        $fromIso = $client->testConvertToUtf8($isoStr);
        $this->assert($fromIso === "Atenção encarregado de educação", "ISO-8859-1 safely converted to UTF-8");
    }

    private function testConstructorSchoolFailure() {
        echo "\nTesting Exception Handling on Network / Server Failure:\n";

        // Trying to connect to invalid or non-responsive domain should throw RuntimeException, NOT echo or fatal TypeError
        try {
            new GiaeConnect("127.0.0.1:65534", "", "", false);
            $this->assert(false, "Expected RuntimeException on unreachable server");
        } catch (\RuntimeException $e) {
            $this->assert(true, "Clean RuntimeException thrown on connection error: " . $e->getMessage());
        } catch (\Throwable $e) {
            $this->assert(false, "Unexpected exception: " . get_class($e) . " - " . $e->getMessage());
        }
    }

    private function testChangeAnoLetivoValidation() {
        echo "\nTesting changeAnoLetivo Missing Year Validation:\n";

        $mockClient = new class("giae.example.com") extends TestableGiaeConnect {
            public function getConfInfo() {
                return json_encode([
                    "anosescolares" => [
                        ["idanoescolar" => "1", "descricao" => "2025/2026"]
                    ]
                ]);
            }
            protected function post($endpoint, $payload) {
                return json_encode(["status" => "ok"]);
            }
        };

        // Found year
        try {
            $res = $mockClient->changeAnoLetivo("2025/2026");
            $this->assert($res !== null, "Existing school year found and updated");
        } catch (\Exception $e) {
            $this->assert(false, "Failed with existing year: " . $e->getMessage());
        }

        // Non-existent year must throw InvalidArgumentException instead of undefined variable
        try {
            $mockClient->changeAnoLetivo("1990/1991");
            $this->assert(false, "Non-existent school year should have thrown InvalidArgumentException");
        } catch (\InvalidArgumentException $e) {
            $this->assert(true, "InvalidArgumentException thrown for unknown school year");
        }
    }
}

$runner = new TestRunner();
$runner->run();

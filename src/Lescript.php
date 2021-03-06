<?php

namespace Analogic\ACME;

use \Psr\Log\LoggerInterface;

class Lescript
{
    public $ca = 'https://acme-v02.api.letsencrypt.org';
    //public $ca = 'https://acme-staging-v02.api.letsencrypt.org'; // testing
    public $countryCode = 'CZ';
    public $state = "Czech Republic";
    public $challenge = 'http-01'; // http-01 challange only
    public $contact = array(); // optional
    // public $contact = array("mailto:cert-admin@example.com", "tel:+12025551212")

    protected $certificatesDir;
	protected $webRootDir;

    /** @var LoggerInterface */
    protected $logger;
    /** @var ClientInterface */
    protected $client;
    protected $accountKeyPath;

    protected $accountId = '';
    protected $urlNewAccount = '';
    protected $urlNewNonce = '';
    protected $urlNewOrder = '';

    protected $domain;
    protected $payload;
    protected $tokenPath;
    protected $challengeUri;
    protected $locationUrl;

    public function __construct($certificatesDir, $webRootDir, $logger = null, ClientInterface $client = null)
    {
        $this->certificatesDir = $certificatesDir;
        $this->webRootDir = $webRootDir;
        $this->logger = $logger;
        $this->client = $client ? $client : new Client($this->ca);
        $this->accountKeyPath = $certificatesDir . '/_account/private.pem';
    }

    public function initAccount()
    {
        $this->initCommunication();

        if (!is_file($this->accountKeyPath)) {

            // generate and save new private key for account
            // ---------------------------------------------

            $this->log('Starting new account registration');
            $this->generateKey(dirname($this->accountKeyPath));
            $this->postNewReg();
            $this->log('New account certificate registered');
        } else {
            $this->log('Account already registered. Continuing.');
            $this->getAccountId();
        }

        if (empty($this->accountId)) {
            throw new RuntimeException("We don't have account ID");
        }

        $this->log("Account: ".$this->accountId);
    }

    public function initCommunication()
    {
        $this->log('Getting list of URLs for API');

        $directory = $this->client->get('/directory');
        if (!isset($directory['newNonce']) || !isset($directory['newAccount']) || !isset($directory['newOrder'])) {
            throw new RuntimeException("Missing setup urls");
        }

        $this->urlNewNonce = $directory['newNonce'];
        $this->urlNewAccount = $directory['newAccount'];
        $this->urlNewOrder = $directory['newOrder'];

        $this->log('Requesting new nonce for client communication');
        $this->client->get($this->urlNewNonce);
    }

    public function authDomain($domain)
    {
        $this->domain = $domain;
        $this->log('Starting certificate generation process for domain');

        $privateAccountKey = $this->readPrivateKey($this->accountKeyPath);
        $accountKeyDetails = openssl_pkey_get_details($privateAccountKey);

        $this->log("Requesting challenge for $domain");

        $response = $this->signedRequest(
            $this->urlNewOrder,
            array("identifiers" => array_map(
                function ($domain) { return array("type" => "dns", "value" => $domain);},
                [$domain]
            ))
        );

        $this->finalizeUrl = $response['finalize'];

        // 1. getting authentication requirements
        // --------------------------------------

        $response = $this->signedRequest($response['authorizations'][0], "");
        $domain = $response['identifier']['value'];
        if(empty($response['challenges'])) {
            throw new RuntimeException("HTTP Challenge for $domain is not available. Whole response: ".json_encode($response));
        }

        $self = $this;
        $challenge = array_reduce($response['challenges'], function ($v, $w) use (&$self) {
            return $v ? $v : ($w['type'] == $self->challenge ? $w : false);
        });
        if (!$challenge) throw new RuntimeException("HTTP Challenge for $domain is not available. Whole response: " . json_encode($response));

        $this->log("Got challenge token for $domain");


        // 2. saving authentication token for web verification
        // ---------------------------------------------------
        $this->tokenPath = $challenge['token'];
        $this->challengeUri = $challenge['url'];

        $header = array(
            // need to be in precise order!
            "e" => Base64UrlSafeEncoder::encode($accountKeyDetails["rsa"]["e"]),
            "kty" => "RSA",
            "n" => Base64UrlSafeEncoder::encode($accountKeyDetails["rsa"]["n"])

        );
        $this->payload = $challenge['token'] . '.' . Base64UrlSafeEncoder::encode(hash('sha256', json_encode($header), true));

        return (object) [
            'token' => $this->tokenPath,
            'payload' => $this->payload,
        ];
    }

    /**
     * verification process itself
     * @param bool $reuseCsr
     */
    public function signDomain($reuseCsr = false)
    {
        $uri = 'http://' . $this->domain . '/.well-known/acme-challenge/' . $this->tokenPath;

        $this->log("Token for " . $this->domain . " saved at " . $this->tokenPath . " and should be available at $uri");

        // simple self check
        if ($this->payload !== trim(@file_get_contents($uri))) {
            throw new \RuntimeException("Please check $uri - token not available");
        }

        $this->log("Sending request to challenge");

        // send request to challenge
        $allowed_loops = 30;
        $result = null;
        while ($allowed_loops > 0) {

            $result = $this->signedRequest(
                $this->challengeUri,
                array("keyAuthorization" => $this->payload)
            );

            if (empty($result['status']) || $result['status'] == "invalid") {
                throw new RuntimeException("Verification ended with error: " . json_encode($result));
            }

            if ($result['status'] != "pending") {
                break;
            }

            $this->log("Verification pending, sleeping 1s");
            sleep(1);

            $allowed_loops--;
        }

        if ($allowed_loops == 0 && $result['status'] === "pending") {
            throw new RuntimeException("Verification timed out");
        }

        $this->log("Verification ended with status: ${result['status']}");


        // requesting certificate
        // ----------------------
        $domainPath = $this->getDomainPath($this->domain);

        // generate private key for domain if not exist
        if (!is_dir($domainPath) || !is_file($domainPath . '/private.pem')) {
            $this->generateKey($domainPath);
        }

        // load domain key
        $privateDomainKey = $this->readPrivateKey($domainPath . '/private.pem');

        $this->client->getLastLinks();

        $csr = $reuseCsr && is_file($domainPath . "/last.csr")?
            $this->getCsrContent($domainPath . "/last.csr") :
            $this->generateCSR($privateDomainKey, [$this->domain]);

        $finalizeResponse = $this->signedRequest($this->finalizeUrl, array('csr' => $csr));

        if ($this->client->getLastCode() > 299 || $this->client->getLastCode() < 200) {
            throw new RuntimeException("Invalid response code: " . $this->client->getLastCode() . ", " . json_encode($finalizeResponse));
        }

        $location = $finalizeResponse['certificate'];

        // waiting loop
        $certificates = array();
        while (1) {
            $this->client->getLastLinks();

            $result = $this->signedRequest($location, "");

            if ($this->client->getLastCode() == 202) {

                $this->log("Certificate generation pending, sleeping 1s");
                sleep(1);

            } else if ($this->client->getLastCode() == 200) {

                $this->log("Got certificate! YAY!");
                $serverCert = $this->parseFirstPemFromBody($result);
                $certificates[] = $serverCert;
                $certificates[] = substr($result, strlen($serverCert)); // rest of ca certs

                break;
            } else {

                throw new RuntimeException("Can't get certificate: HTTP code " . $this->client->getLastCode());

            }
        }

        if (empty($certificates)) throw new \RuntimeException('No certificates generated');

        $this->log("Saving fullchain.pem");
        file_put_contents($domainPath . '/fullchain.pem', implode("\n", $certificates));

        $this->log("Saving cert.pem");
        file_put_contents($domainPath . '/cert.pem', array_shift($certificates));

        $this->log("Saving chain.pem");
        file_put_contents($domainPath . "/chain.pem", implode("\n", $certificates));

        $this->log("Done !!§§!");
    }

    public function signDomains(array $domains, $reuseCsr = false)
    {
        $this->log('Starting certificate generation process for domains');

        $privateAccountKey = $this->readPrivateKey($this->accountKeyPath);
        $accountKeyDetails = openssl_pkey_get_details($privateAccountKey);

        // start domains authentication
        // ----------------------------

        $this->log("Requesting challenge for ".join(', ', $domains));
        $response = $this->signedRequest(
            $this->urlNewOrder,
            array("identifiers" => array_map(
                function ($domain) { return array("type" => "dns", "value" => $domain);},
                $domains
            ))
        );

        $finalizeUrl = $response['finalize'];

        foreach ($response['authorizations'] as $authz) {
            // 1. getting authentication requirements
            // --------------------------------------

            $response = $this->signedRequest($authz, "");
            $domain = $response['identifier']['value'];
            if(empty($response['challenges'])) {
                throw new RuntimeException("HTTP Challenge for $domain is not available. Whole response: ".json_encode($response));
            }

            $self = $this;
            $challenge = array_reduce($response['challenges'], function ($v, $w) use (&$self) {
                return $v ? $v : ($w['type'] == $self->challenge ? $w : false);
            });
            if (!$challenge) throw new RuntimeException("HTTP Challenge for $domain is not available. Whole response: " . json_encode($response));

            $this->log("Got challenge token for $domain");

            // 2. saving authentication token for web verification
            // ---------------------------------------------------
            $directory = $this->webRootDir . '/.well-known/acme-challenge';
            $tokenPath = $directory . '/' . $challenge['token'];

            if (!file_exists($directory) && !@mkdir($directory, 0755, true)) {
                throw new RuntimeException("Couldn't create directory to expose challenge: ${tokenPath}");
            }

            $header = array(
                // need to be in precise order!
                "e" => Base64UrlSafeEncoder::encode($accountKeyDetails["rsa"]["e"]),
                "kty" => "RSA",
                "n" => Base64UrlSafeEncoder::encode($accountKeyDetails["rsa"]["n"])

            );
            $payload = $challenge['token'] . '.' . Base64UrlSafeEncoder::encode(hash('sha256', json_encode($header), true));

            file_put_contents($tokenPath, $payload);
            chmod($tokenPath, 0644);

            // 3. verification process itself
            // -------------------------------

            $uri = "http://${domain}/.well-known/acme-challenge/${challenge['token']}";

            $this->log("Token for $domain saved at $tokenPath and should be available at $uri");

            // simple self check
            if ($payload !== trim(@file_get_contents($uri))) {
                throw new RuntimeException("Please check $uri - token not available");
            }

            $this->log("Sending request to challenge");


            // send request to challenge
            $allowed_loops = 30;
            $result = null;
            while ($allowed_loops > 0) {

                $result = $this->signedRequest(
                    $challenge['url'],
                    array("keyAuthorization" => $payload)
                );

                if (empty($result['status']) || $result['status'] == "invalid") {
                    throw new RuntimeException("Verification ended with error: " . json_encode($result));
                }

                if ($result['status'] != "pending") {
                    break;
                }

                $this->log("Verification pending, sleeping 1s");
                sleep(1);

                $allowed_loops--;
            }

            if ($allowed_loops == 0 && $result['status'] === "pending") {
                throw new RuntimeException("Verification timed out");
            }

            $this->log("Verification ended with status: ${result['status']}");

            @unlink($tokenPath);
        }

        // requesting certificate
        // ----------------------
        $domainPath = $this->getDomainPath(reset($domains));

        // generate private key for domain if not exist
        if (!is_dir($domainPath) || !is_file($domainPath . '/private.pem')) {
            $this->generateKey($domainPath);
        }

        // load domain key
        $privateDomainKey = $this->readPrivateKey($domainPath . '/private.pem');

        $this->client->getLastLinks();

        $csr = $reuseCsr && is_file($domainPath . "/last.csr")?
            $this->getCsrContent($domainPath . "/last.csr") :
            $this->generateCSR($privateDomainKey, $domains);

        $finalizeResponse = $this->signedRequest($finalizeUrl, array('csr' => $csr));

        if ($this->client->getLastCode() > 299 || $this->client->getLastCode() < 200) {
            throw new RuntimeException("Invalid response code: " . $this->client->getLastCode() . ", " . json_encode($finalizeResponse));
        }

        $location = $finalizeResponse['certificate'];

        // waiting loop
        $certificates = array();
        while (1) {
            $this->client->getLastLinks();

            $result = $this->signedRequest($location, "");

            if ($this->client->getLastCode() == 202) {

                $this->log("Certificate generation pending, sleeping 1s");
                sleep(1);

            } else if ($this->client->getLastCode() == 200) {

                $this->log("Got certificate! YAY!");
                $serverCert = $this->parseFirstPemFromBody($result);
                $certificates[] = $serverCert;
                $certificates[] = substr($result, strlen($serverCert)); // rest of ca certs

                break;
            } else {

                throw new RuntimeException("Can't get certificate: HTTP code " . $this->client->getLastCode());

            }
        }

        if (empty($certificates)) throw new RuntimeException('No certificates generated');

        $this->log("Saving fullchain.pem");
        file_put_contents($domainPath . '/fullchain.pem', implode("\n", $certificates));

        $this->log("Saving cert.pem");
        file_put_contents($domainPath . '/cert.pem', array_shift($certificates));

        $this->log("Saving chain.pem");
        file_put_contents($domainPath . "/chain.pem", implode("\n", $certificates));

        $this->log("Done !!§§!");
    }

	protected function readPrivateKey($path)
    {
        if (($key = openssl_pkey_get_private('file://' . $path)) === FALSE) {
            throw new RuntimeException(openssl_error_string());
        }

        return $key;
    }

    protected function parseFirstPemFromBody($body)
    {
        preg_match('~(-----BEGIN.*?END CERTIFICATE-----)~s', $body, $matches);

        return $matches[1];
    }

    protected function getDomainPath($domain)
    {
        return $this->certificatesDir . '/' . $domain . '/';
    }

    protected function getAccountId()
    {
        return $this->postNewReg();
    }

    protected function postNewReg()
    {
        $data = array(
            'termsOfServiceAgreed' => true
        );

        $this->log('Sending registration to letsencrypt server');

        if($this->contact) {
            $data['contact'] = $this->contact;
        }

        $response = $this->signedRequest(
            $this->urlNewAccount,
            $data
        );
        $lastLocation = $this->client->getLastLocation();
        if (!empty($lastLocation)) {
            $this->accountId = $lastLocation;
        }
        return $response;
    }

    protected function generateCSR($privateKey, array $domains)
    {
        $domain = reset($domains);
        $san = implode(",", array_map(function ($dns) {
            return "DNS:" . $dns;
        }, $domains));
        $tmpConf = tmpfile();
        $tmpConfMeta = stream_get_meta_data($tmpConf);
        $tmpConfPath = $tmpConfMeta["uri"];

        // workaround to get SAN working
        fwrite($tmpConf,
            'HOME = .
RANDFILE = $ENV::HOME/.rnd
[ req ]
default_bits = 2048
default_keyfile = privkey.pem
distinguished_name = req_distinguished_name
req_extensions = v3_req
[ req_distinguished_name ]
countryName = Country Name (2 letter code)
[ v3_req ]
basicConstraints = CA:FALSE
subjectAltName = ' . $san . '
keyUsage = nonRepudiation, digitalSignature, keyEncipherment');

        $csr = openssl_csr_new(
            array(
                "CN" => $domain,
                "ST" => $this->state,
                "C" => $this->countryCode,
                "O" => "Unknown",
            ),
            $privateKey,
            array(
                "config" => $tmpConfPath,
                "digest_alg" => "sha256"
            )
        );

        if (!$csr) throw new RuntimeException("CSR couldn't be generated! " . openssl_error_string());

        openssl_csr_export($csr, $csr);
        fclose($tmpConf);

        $csrPath = $this->getDomainPath($domain) . "/last.csr";
        file_put_contents($csrPath, $csr);

        return $this->getCsrContent($csrPath);
    }

	protected function getCsrContent($csrPath) {
        $csr = file_get_contents($csrPath);

        preg_match('~REQUEST-----(.*)-----END~s', $csr, $matches);

        return trim(Base64UrlSafeEncoder::encode(base64_decode($matches[1])));
    }

	protected function generateKey($outputDirectory)
    {
        $res = openssl_pkey_new(array(
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
            "private_key_bits" => 4096,
        ));

        if(!openssl_pkey_export($res, $privateKey)) {
            throw new RuntimeException("Key export failed!");
        }

        $details = openssl_pkey_get_details($res);

        if(!is_dir($outputDirectory)) @mkdir($outputDirectory, 0700, true);
        if(!is_dir($outputDirectory)) throw new RuntimeException("Cant't create directory $outputDirectory");

        file_put_contents($outputDirectory.'/private.pem', $privateKey);
        file_put_contents($outputDirectory.'/public.pem', $details['key']);
    }

    protected function signedRequest($uri, $payload, $nonce = null)
    {
        $privateKey = $this->readPrivateKey($this->accountKeyPath);
        $details = openssl_pkey_get_details($privateKey);

        $protected = array(
            "alg" => "RS256",
            "nonce" => $nonce ? $nonce : $this->client->getLastNonce(),
            "url" => $uri
        );

        if ($this->accountId) {
            $protected["kid"] = $this->accountId;
        } else {
            $protected["jwk"] = array(
                "kty" => "RSA",
                "n" => Base64UrlSafeEncoder::encode($details["rsa"]["n"]),
                "e" => Base64UrlSafeEncoder::encode($details["rsa"]["e"]),
            );
        }

        $payload64 = Base64UrlSafeEncoder::encode(empty($payload) ? "" : str_replace('\\/', '/', json_encode($payload)));
        $protected64 = Base64UrlSafeEncoder::encode(json_encode($protected));

        openssl_sign($protected64.'.'.$payload64, $signed, $privateKey, "SHA256");

        $signed64 = Base64UrlSafeEncoder::encode($signed);

        $data = array(
            'protected' => $protected64,
            'payload' => $payload64,
            'signature' => $signed64
        );

        $this->log("Sending signed request to $uri");

        return $this->client->post($uri, json_encode($data));
    }

    protected function log($message)
    {
        if($this->logger) {
            $this->logger->info($message);
        } else {
            echo $message."\n";
        }
    }
}

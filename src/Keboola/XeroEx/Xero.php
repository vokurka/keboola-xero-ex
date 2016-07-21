<?php

use Keboola\Json\Parser;

require_once dirname(__FILE__).'/XeroOAuth-PHP/lib/XeroOAuth.php';

class Xero
{
	private $signatures = array(
		'application_type' => 'Private',
		'oauth_callback' => 'oob',
		'user_agent' => "Keboola Connection Extractor",

		'consumer_key' => NULL,
		'shared_secret' => NULL,

		'rsa_private_key' => NULL,
		'rsa_public_key' => NULL,

		'core_version'=> '2.0',
		'payroll_version'=> '1.0',
		'file_version' => '1.0',
	);

	private $mandatoryConfigColumns = array(
		'bucket',
		'consumer_key', 
		'#consumer_secret', 
		'#private_key',
		'public_key',
		'parameters',
		'endpoint',
	);

	private $destination;

	private $config;

	public function __construct($config, $destination)
	{
		date_default_timezone_set('UTC');
		$this->destination = $destination;

		foreach ($this->mandatoryConfigColumns as $c)
		{
			if (!isset($config[$c])) 
			{
				throw new Exception("Mandatory column '{$c}' not found or empty.");
			}

			$this->config[$c] = $config[$c];
		}

		if (!file_exists(dirname(__FILE__)."/certs/"))
		{
			mkdir(dirname(__FILE__)."/certs/");
		}

		if (!file_exists(dirname(__FILE__)."/certs/ca-bundle.crt"))
		{
			$cert = file_get_contents("http://curl.haxx.se/ca/cacert.pem");

			if (empty($cert)) 
			{
				throw new Exception("Cannot load SSL certificate for comms.");
			}

			file_put_contents(dirname(__FILE__)."/certs/ca-bundle.crt", $cert);
		}

		$this->prepareCertificates();

		$this->signatures['consumer_key'] = $this->config['consumer_key'];
		$this->signatures['access_token'] = $this->config['consumer_key'];
		$this->signatures['shared_secret'] = $this->config['#consumer_secret'];
		$this->signatures['access_token_secret'] = $this->config['#consumer_secret'];
		$this->signatures['rsa_private_key'] = dirname(__FILE__)."/certs/privatekey";
		$this->signatures['rsa_public_key'] = dirname(__FILE__)."/certs/publickey";

		$this->xero = new XeroOAuth($this->signatures);

		foreach (array('date', 'fromDate', 'toDate') as $date)
		{
			if (!empty($this->config['parameters'][$date]))
			{
				$timestamp = strtotime($this->config['parameters'][$date]);
				$dateTime = new DateTime();
				$dateTime->setTimestamp($timestamp);

				$this->config['parameters'][$date] = $dateTime->format('Y-m-d');
			}
		}
	}

	public function run()
	{
		$url = $this->xero->url($this->config['endpoint']);

		$response = $this->xero->request('GET', $url, $this->config['parameters'], '', 'json');

		if ($response['code'] != '200')
		{
			throw new Exception("Request to the API failed: ".$response['code'].": ".$response['response']);
		}

		$this->write($response['response']);
	}

	private function write($result)
	{
		$json = json_decode($result);

		$parser = Parser::create(new \Monolog\Logger('json-parser'));
		$parser->process(array($json), str_replace('/', '_', $this->config['endpoint']));
		$result = $parser->getCsvFiles();

		foreach ($result as $file)
		{
			copy($file->getPathName(), $this->destination.$this->config['bucket'].'.'.substr($file->getFileName(), strpos($file->getFileName(), '-')+1));
		}
	}

	private function prepareCertificates()
	{
		foreach (array('#private_key' => 'privatekey', 'public_key' => 'publickey') as $configName => $fileName)
		{
			$cert = str_replace("\\n", "\n", $this->config[$configName]);
			file_put_contents(dirname(__FILE__)."/certs/".$fileName, $cert);
		}	
	}
}
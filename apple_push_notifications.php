<?php

/**
 * ApplePushNotifications class
 *
 * This source file can be used to communicate with the Apple Push Notification Server (APNS)
 *
 * The class is documented in the file itself. If you find any bugs help me out and report them. Reporting can be done by sending an email to php-apple-push-notifications-bugs[at]verkoyen[dot]eu.
 * If you report a bug, make sure you give me enough information (include your code).
 *
 * License
 * Copyright (c), Tijs Verkoyen. All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
 * 2. Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
 * 3. The name of the author may not be used to endorse or promote products derived from this software without specific prior written permission.
 *
 * This software is provided by the author "as is" and any express or implied warranties, including, but not limited to, the implied warranties of merchantability and fitness for a particular purpose are disclaimed. In no event shall the author be liable for any direct, indirect, incidental, special, exemplary, or consequential damages (including, but not limited to, procurement of substitute goods or services; loss of use, data, or profits; or business interruption) however caused and on any theory of liability, whether in contract, strict liability, or tort (including negligence or otherwise) arising in any way out of the use of this software, even if advised of the possibility of such damage.
 *
 * @author			Tijs Verkoyen <php-apple-push-notifications@verkoyen.eu>
 * @version			1.0.0
 *
 * @copyright		Copyright (c), Tijs Verkoyen. All rights reserved.
 * @license			BSD License
 */
class ApplePushNotifications
{
	// current version
	const VERSION = '1.0.0';


	/**
	 * The passphrase to use.
	 *
	 * @var	string
	 */
	private $certificatePassphrase;


	/**
	 * Path to the certificate
	 *
	 * @var	string
	 */
	private $certificatePath;


	/**
	 * The feedback client
	 *
	 * @var	resource
	 */
	private $feedbackClient;


	/**
	 * Is the app in production?
	 *
	 * @var bool
	 */
	private $isProduction = true;


	/**
	 * The push client
	 *
	 * @var	resource
	 */
	private $pushClient;


	/**
	 * The timeout
	 *
	 * @var	int
	 */
	private $timeOut = 10;


// class methods
	/**
	 * Default constructor
	 * Howto create the certificate:
	 *  1. Log in to the iPhone Developer Connection Portal (http://developer.apple.com/iphone/manage/overview/index.action) and click App IDs.
	 *  2. Ensure you have created an App ID without a wildcard. Wildcard IDs cannot use the push notification service. For example, our iPhone application ID looks something like AB123346CD.eu.verkoyen.iphone
	 *  3. Click "Configure" next to your App ID and then click the button to generate a Push Notification certificate. A wizard will appear guiding you through the steps to generate a signing authority and then upload it to the portal, then download the newly generated certificate. This step is also covered in the Apple documentation.
	 *  4. Import your aps_developer_identity.cer into your Keychain by double clicking the .cer file.
	 *  5. Launch Keychain Assistant from your local Mac and from the login keychain, filter by the Certificates category. You will see an expandable option called "Apple Development Push Services"
	 *  6. Expand this option then right click on "Apple Development Push Services" > Export "Apple Development Push Services ID123". Save this as apns_dev_cert.p12 file somewhere you can access it.
	 *  7. Do the same again for the "Private Key" that was revealed when you expanded "Apple Development Push Services" ensuring you save it as apns_dev_key.p12 file.
	 *  8. These files now need to be converted to the PEM format by executing this command from the terminal:
	 *  	openssl pkcs12 -clcerts -nokeys -out apns_dev_cert.pem -in apns_dev_cert.p12
	 *  	openssl pkcs12 -nocerts -out apns_dev_key.pem -in apns_dev_key.p12
	 *  10. Finally, you need to combine the key and cert files into a apns_dev.pem file we will use when connecting to APNS:
	 *  	cat apns_dev_cert.pem apns_dev_key.pem > apns_dev.pem
	 *
	 * @return	void
	 * @param	string $certificatePath			The path to the certificate to use.
	 * @param	bool[optional] $production		Is this application in production-mode?
	 */
	public function __construct($certificatePath, $production = true)
	{
		$this->setCertificatePath((string) $certificatePath);
		$this->setMode((bool) $production);
	}


	/**
	 * Default destructor
	 *
	 * @return	void
	 */
	public function __destruct()
	{
		// close connection
		if($this->pushClient !== null) @fclose($this->pushClient);
		if($this->feedbackClient !== null) @fclose($this->feedbackClient);
	}


	/**
	 * Connect to push-server
	 *
	 * @return	void
	 */
	private function connectToPush()
	{
		// build options
		$options = array();
		$options['ssl']['local_cert'] = $this->getCertificatePath();
		$options['ssl']['verify_peer'] = false;
		if($this->getCertificatePassphrase() != '') $options['ssl']['passphrase'] = $this->getCertificatePassphrase();

		// create the stream
		$stream = stream_context_create($options);

		// set endpoint
		if($this->isProduction) $endPoint = 'ssl://gateway.push.apple.com:2195';
		else $endPoint = 'ssl://gateway.sandbox.push.apple.com:2195';

		// init vars
		$errorNumber = 0;
		$errorString = '';

		// create the client
		$this->pushClient = @stream_socket_client($endPoint, $errorNumber, $errorString, $this->getTimeOut(), STREAM_CLIENT_CONNECT, $stream);

		// validate response
		if($errorNumber != 0 || $errorString != '') throw new ApplePushNotificationsException((string) $errorString, (int) $errorNumber);

		// no "errors", but not a valid client
		if($this->pushClient === false) throw new ApplePushNotificationsException('Something went wrong.');
	}


	/**
	 * Connect to feedback-server
	 *
	 * @return	void
	 */
	private function connectToFeedback()
	{
		// build options
		$options = array();
		$options['ssl']['local_cert'] = $this->getCertificatePath();
		$options['ssl']['verify_peer'] = false;
		if($this->getCertificatePassphrase() != '') $options['ssl']['passphrase'] = $this->getCertificatePassphrase();

		// create the stream
		$stream = stream_context_create($options);

		// set endpoint
		if($this->isProduction) $endPoint = 'ssl://feedback.push.apple.com:2196';
		else $endPoint = 'ssl://feedback.sandbox.push.apple.com:2196';

		// init vars
		$errorNumber = 0;
		$errorString = '';

		// create the client
		$this->feedbackClient = stream_socket_client($endPoint, $errorNumber, $errorString, $this->getTimeOut(), STREAM_CLIENT_CONNECT, $stream);

		// validate response
		if($errorNumber != 0 || $errorString != '') throw new ApplePushNotificationsException((string) $errorString, (int) $errorNumber);

		// no "errors", but not a valid client
		if($this->feedbackClient === false) throw new ApplePushNotificationsException('Something went wrong.');
	}


	/**
	 * Get the certificates passphrase
	 *
	 * @return	string
	 */
	private function getCertificatePassphrase()
	{
		return $this->certificatePassphrase;
	}


	/**
	 * Get the certificate path
	 *
	 * @return	string
	 */
	private function getCertificatePath()
	{
		return $this->certificatePath;
	}


	/**
	 * Get the timeout that will be used
	 *
	 * @return	int
	 */
	public function getTimeOut()
	{
		return (int) $this->timeOut;
	}


	/**
	 * Set the passphrase to use.
	 *
	 * @return	void
	 * @param	string $passphrase	The passphrase to use.
	 */
	public function setCertificatePassphrase($passphrase)
	{
		$this->certificatePassphrase = (string) $passphrase;
	}


	/**
	 * Set the path to the certificate
	 *
	 * @return	void
	 * @param	string $path	The path to the certificate.
	 */
	private function setCertificatePath($path)
	{
		$this->certificatePath = (string) $path;
	}


	/**
	 * Set the mode wherin the application operates
	 *
	 * @return	void
	 * @param	bool[optional] $production	Is it production?
	 */
	public function setMode($production = true)
	{
		$this->isProduction = (bool) $production;
	}


	/**
	 * After this time the request will stop. You should handle any errors triggered by this.
	 *
	 * @return	void
	 * @param	int $seconds	The timeout in seconds.
	 */
	public function setTimeOut($seconds)
	{
		$this->timeOut = (int) $seconds;
	}


	/**
	 * Get feedback from the feedback-service. It wil return a list of device-tokens that have repeatedly reported failed-delivery attempts.
	 *
	 * @return	array
	 */
	public function feedback()
	{
		// already connected?
		if($this->feedbackClient === null) $this->connectToFeedback();

		// init vars
		$return = array();

		// keep reading untill the end
		while(!feof($this->feedbackClient))
		{
			// read and store
			$data = fread($this->feedbackClient, 38);

			// add to return
			if(strlen($data)) $return[] = unpack('Ntimestamp/ntoken_length/H*device_token', $data);
		}

		// close
		@fclose($this->feedbackClient);

		// return
		return $return;
	}


	/**
	 * Push a notification to a device
	 *
	 * @return	void
	 * @param	string $deviceToken					The token of the device to send the notification to.
	 * @param	mixed $alert						The message of dictionary to send.
	 * @param	int[optional] $badge				The number that has to appear in the badge.
	 * @param	string[optional] $sound				The sound to use.
	 * @param	array[optional] $extraDictionaries	Some extra dictionaries.
	 */
	public function push($deviceToken, $alert, $badge = null, $sound = 'default', array $extraDictionaries = null)
	{
		// redefine
		$deviceToken = str_replace(' ', '', (string) $deviceToken);

		// build the payload
		$payLoadData = (array) $extraDictionaries;

		// apps
		$payLoadData['aps']['alert'] = $alert;
		$payLoadData['aps']['sound'] = (string) $sound;
		if($badge !== null) $payLoadData['aps']['badge'] = (int) $badge;

		// convert payload into JSON
		$payLoad = json_encode($payLoadData);

		// already connected?
		if($this->pushClient === null) $this->connectToPush();

		// build the message
		$message = chr(0) . chr(0) . chr(32) . pack('H*', $deviceToken) . chr(0) . chr(strlen($payLoad)) . $payLoad;

		// write the message
		fwrite($this->pushClient, $message);
	}
}


/**
 * ApplePushNotifications Exception class
 *
 * @author	Tijs Verkoyen <php-apple-push-notifications@verkoyen.eu>
 */
class ApplePushNotificationsException extends Exception
{
}

?>
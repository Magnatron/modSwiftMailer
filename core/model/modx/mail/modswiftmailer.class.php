<?php
/**
 * modSwiftMailer
 *
 * Copyright 2011 by Mark Ernst (ReSpawN) <info@markernst.nl>
 *
 * modSwiftMailer is free software; you can redistribute it and/or modify it under the
 * terms of the GNU General Public License as published by the Free Software
 * Foundation; either version 2 of the License, or (at your option) any later
 * version.
 *
 * modSwiftMailer is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR
 * A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * modSwiftMailer; if not, write to the Free Software Foundation, Inc., 59 Temple
 * Place, Suite 330, Boston, MA 02111-1307 USA
 *
 * @package		modSwiftMailer
 */

/**
 * MODX
 * 
 * @package		MODX
 * @subpackage	Revolution
 * @link		http://www.modx.com/
 * @copyright	2005-2011, MODX, LLC
 * @license		GPLv2
 * @file		modx.class.php
 * @see			mail/modmail.class.php
 */

/**
 * modSwiftMailer
 * 
 * modSwiftMailer's main class used in conjunction with MODX Revolution's modMail
 *
 * @name		modSwiftMailer
 * @package		modSwiftMailer
 * @subpackage	Swift Mailer
 * @author		Mark Ernst (ReSpawN)
 * @link		http://www.markernst.nl/modswiftmailer/
 * @link		http://swiftmailer.org/
 * @copyright	2011, Mark Ernst (ReSpawN)
 * @license		GPLv2
 * @access		public
 * @compatible	MODX Revolution 2.1.2-pl
 *
 * @since		July 14th, 2011 (07-14-2011)
 * @version		0.3.1-pl
 */

require_once($this->getOption('core_path').'model/modx/mail/modmail.class.php');

class modSwiftMailer extends modMail {
	const package = 'modSwiftMailer';
	const major = 0;
	const minor = 3;
	const patch = 1;
	const release = 'pl';
	const index = '';
	
	const MAIL_TO = 'mail_to';
	const MAIL_CC = 'mail_cc';
	const MAIL_BCC = 'mail_bcc';
	const MAIL_RETURN_PATH = 'mail_return_path';
	
	public $message = null;
	public $mailer = null;
	
	private $_logger = '';
	
	// Addresses collection
	protected $_to = array();
	protected $_cc = array();
	protected $_bcc = array();
	
	// Mail specific collection
	protected $_headers = array();
	protected $_transport = null;
	protected $_preferences = null;
	
	// Collection of allowed plugins (and stacked plugins)
	protected $_plugins = array(
		'decorator' => array(
			'active' => false
		),
		'throttler' => array(
			'active' => false,
			'rate' => 100,
			'mode' => 'messages'
		),
		'antiflood' => array(
			'active' => false,
			'threshold' => 100,
			'sleep' => 5
		)
	);
	
	// Class' trunk
	protected $_config = array();
	protected $_debug = false;
	
	/**
	 * __construct
	 * 
	 * @name	__construct
	 * @access	public
	 * 
	 * @param	object	$modx		(modX) MODX object
	 * @param	array	$config		(optional) Configuration
	 * 
	 * @return	mixed	Result of the automatic initialized (if accesable)
	 */
    public function __construct(modX &$modx, $config = array()) {
        parent::__construct($modx, $config);
		
		$this->_config = array_merge($this->_config, array(
			'autoreset' => true,
			'validateemails' => true
		), $config);
		
		// Auto-initialize the debugger
		if (isset($this->_config['debug']) && $this->_config['debug'] == true) {
			$this->debug(true);
		}
		
		$this->_getMailer(((isset($config['attributes']) && !empty($config['attributes'])) ? $config['attributes'] : array()));
		
        if ($this->_transport == null) {
			$this->modx->log(modX::LOG_LEVEL_ERROR, 'modSwiftMailer could not be loaded');
			return false;
		}
		
		return true;
    }
	
	/**
	 * set
	 * 
	 * Sets a Swift Mailer attribute corresponding to the modMail::MAIL_* constants or a custom key.
	 * Overrides the attributes in modMail for further usage.
	 * 
	 * Backwards compatibility with modPHPMailer. Use the apply named functions instead.
	 * Still has limited control over the transportation and building of the e-mail.
	 * 
	 * Some functions are deprecated by design, so there will no need to feature-request these. Any other functionality
	 * missing can be requested in the bugtracker. Please be aware that most configuration should be done beforehand
	 * and modSwiftMailer is only a means to sending an e-mail, not repurposing MODX's default settings, such as SMTP.
	 * 
	 * @name	set
	 * @access	public
	 * 
	 * @param	mixed	$key		(string or modMail constant) The key to set
	 * @param	mixed	$value		Any valid value
	 * 
	 * @return	object	message object
	 */
	public function set($key, $value) {
		parent::set($key, $value);
		
		switch($key) {
			// Message specific
			case self::MAIL_TO:
				// @see address:to
				// Does not support $name or associative name overwrites
				return $this->address('to', $this->attributes[$key]);
				break;
			case self::MAIL_CC:
				// @see address:to
				// Does not support $name or associative name overwrites
				return $this->address('cc', $this->attributes[$key]);
				break;
			case self::MAIL_BCC:
				// @see address:to
				// Does not support $name or associative name overwrites
				return $this->address('bcc', $this->attributes[$key]);
				break;
			case self::MAIL_SUBJECT:
				return $this->subject($this->attributes[$key]);
				break;
			case self::MAIL_BODY:
				return $this->body($this->attributes[$key]);
				break;
			case self::MAIL_SENDER:
				return $this->sender($this->attributes[$key]);
				break;
			case self::MAIL_CHARSET:
				// Only set the new setting if the object has been set (not available on autoload as set, but as default)
				if ($this->_preferences != null) {
					$this->_preferences->setCharset($this->attributes[$key]);
				}
				break;
			case self::MAIL_BODY_TEXT:
				return $this->body($this->attributes[$key], 'text/plain');
				break;
			case self::MAIL_FROM:
				if (!isset($this->attributes[self::MAIL_FROM_NAME]) || $this->attributes[self::MAIL_FROM] == '') {
					break;
				}
				
				return $this->address('from', $this->attributes[self::MAIL_FROM], $this->attributes[self::MAIL_FROM_NAME]);
				break;
			case self::MAIL_FROM_NAME:
				// @deprecated	18th of July, 2011 (2011-07-18)		Use @see address instead, only supports an array (if you want e-mail and name)
				if (!isset($this->attributes[self::MAIL_FROM]) || $this->attributes[self::MAIL_FROM] == '') {
					break;
				}
				
				return $this->address('from', $this->attributes[self::MAIL_FROM], $this->attributes[self::MAIL_FROM_NAME]);
				break;
			case self::MAIL_HOSTNAME:
				// @deprecated	18th of July, 2011 (2011-07-18)		Not supported by Swift Mailer 4.0.1
				break;
			case self::MAIL_LANGUAGE:
				// @deprecated	18th of July, 2011 (2011-07-18)		Not supported by Swift Mailer 4.0.1
				// @see	header
				// Make sure the format is something similar to en-us, en or nl-nl, nl
				$this->header('Accept-Language', $this->attributes[$key]);
				break;
			case self::MAIL_PRIORITY:
				return $this->priority($this->attributes[$key]);
				break;
			case self::MAIL_READ_TO:
				return $this->readReceipt($this->attributes[$key]);
				break;
			case self::MAIL_RETURN_PATH:
				return $this->returnPath($this->attributes[$key]);
				break;
			case self::MAIL_CONTENT_TYPE:
				// @deprecated	18th of July, 2011 (2011-07-18)		Only rewrites default behaviour on (@see body, @see plain)
				break;			
			case self::MAIL_ENCODING:
				// @deprecated	18th of July, 2011 (2011-07-18)		Only rewrites default behaviour on (@see body, @see plain)
				break;
			case self::MAIL_ENGINE:
				// @deprecated	18th of July, 2011 (2011-07-18)		If differs from default (mail() or smtp), pass with getService
				break;
			case self::MAIL_ENGINE_PATH:
				// @deprecated	18th of July, 2011 (2011-07-18)		If differs from default (sendmail()), pass with getService or System Settings
				break;
			case self::MAIL_SMTP_AUTH:
				// @deprecated	18th of July, 2011 (2011-07-18)		Most SMTP servers don't need it anymore
				break;
			case self::MAIL_SMTP_HELO:
				// @deprecated	18th of July, 2011 (2011-07-18)		KeepAlive prefered
				break;
			case self::MAIL_SMTP_HOSTS:
				// Only set the new setting if the object has been set (not available on autoload as set, but as default)
				if ($this->_transport != null) {
					$this->_transport->setHost($this->attributes[$key]);
				}
				break;
			case self::MAIL_SMTP_PORT:
				if ($this->_transport != null) {
					$this->_transport->setPort($this->attributes[$key]);
				}
				break;
			case self::MAIL_SMTP_KEEPALIVE:
				// @deprecated	18th of July, 2011 (2011-07-18)		Not supported by Swift Mailer 4.0.1
				break;
			case self::MAIL_SMTP_PREFIX:
				// @deprecated	18th of July, 2011 (2011-07-18)		Not supported by Swift Mailer 4.0.1
				break;
			case self::MAIL_SMTP_SINGLE_TO:
				// @deprecated	18th of July, 2011 (2011-07-18)		Not supported by Swift Mailer 4.0.1
				break;
			case self::MAIL_SMTP_TIMEOUT:
				if ($this->_transport != null) {
					$this->_transport->setTimeout($this->attributes[$key]);
				}
				break;
			case self::MAIL_SMTP_USER:
				if ($this->_transport != null) {
					$this->_transport->setUsername($this->attributes[$key]);
				}
				break;
			case self::MAIL_SMTP_PASS:
				if ($this->_transport != null) {
					$this->_transport->setPassword($this->attributes[$key]);
				}
				break;
		}
		
		return $this->message;
	}
	
	/**
	 * setHTML
	 * 
	 * Sets the Content-Type to text/html with a charset (defaults to UTF-8)
	 * Overrides the expected modMail::setHTML native that toggles.
	 * 
	 * @name	setHTML
	 * @access	public
	 * 
	 * @param	string	$charset	Type of charset for the text/html Content-Type (defaults to UTF-8)
	 * 
	 * @return	void
	 */
    public function setHTML($charset = 'UTF-8') {
        $headers = $this->message->getHeaders();
		$header = $headers->get('Content-Type');
		
		$header->setValue('text/html');
		$header->setParameter($charset);
    }
	
	/**
	 * toggleHTML
	 * 
	 * Toggles the Content-Type (defaults to text/html) with a charset (defaults to UTF-8)
	 * Switched between text/html and text/plain
	 * 
	 * @name	toggleHTML
	 * @access	public
	 * 
	 * @param	string	$charset	Type of charset for the text/html Content-Type (defaults to UTF-8)
	 * 
	 * @return	void
	 */
    public function toggleHTML($charset = 'UTF-8') {
        $header = $this->message->getHeaders()->get('Content-Type');
		
		if ($header->getValue() == 'text/html') {
			$header->setValue('text/plain');
		} else {
			$header->setValue('text/html');
		}
		
		$header->setParameter($charset);
    }
	
	/**
	 * setPlain
	 * 
	 * Sets the Content-Type to text/plain with a charset (defaults to UTF-8)
	 * 
	 * @name	setHTML
	 * @access	public
	 * 
	 * @param	string	$charset	Type of charset for the text/html Content-Type (defaults to UTF-8)
	 * 
	 * @return	void
	 */
	public function setPlain($charset = 'utf-8') {
		$header = $this->message->getHeaders()->get('Content-Type');
		
		$header->setValue('text/plain');
		$header->setParameter($charset);
	}

	/**
	 * address
	 * 
	 * Adds a specific type of address to the mailer
	 * 
	 * @name	address
	 * @access	public
	 * 
	 * @param	string	$type		The type of addition (to, cc, bcc (and aliasses))
	 * @param	mixed	$email		(string or array) Plain e-mail address or a (associative) array
	 * @param	string	$name		Default name for a plain @param $email, overrides addressee names if set 
	 *								with associative arrays (anonymous or not)
	 * 
	 * @example $modx->mail->address('to', 'modxswiftmailer@domain.tld');
	 * @example $modx->mail->address('cc', 'myself@mydomain.tld', 'Myself');
	 * @example $modx->mail->address('bcc', array('myself@mydomain.tpl' => 'Myself', 'another@domain.tld'));
	 * 
	 * @return	bool	Result of adding the address (false if unspecific type)
	 */
	public function address($type, $email, $name = '') {
		if ($this->message == null) {
			return false;
		}
		
		switch(strtolower($type)) {
			case 'to':
			case 'receiver':
			case 'addressee':
				if (is_array($email)) {
					foreach($email as $key => $value) {
						// Overwrites the name on ALL inserted $emails
						if ($name != '') {
							$key = $name;
						}
						
						if (!is_numeric($key)) {
							// Validate E-mail/Name combination
							if ($this->modx->getOption('validateemails', $this->_config, true)) {
								if (!$this->_validate('email', $value)) {
									continue;
								}
							}
							
							$this->_to[$value] = $key;
						} else {
							$this->_to[] = $value;
						}
					}
				} else {
					// Validate e-mail
					if ($this->modx->getOption('validateemails', $this->_config, true)) {
						if (!$this->_validate('email', $email)) {
							break;
						}
					}

					if ($name != '') {
						$this->_to[$email] = $name;
					} else {
						$this->_to[] = $email;
					}
				}
				break;
			case 'cc':
			case 'carboncopy':
				if (is_array($email)) {
					foreach($email as $key => $value) {
						// Overwrites the name on ALL inserted $emails
						if ($name != '') {
							$key = $name;
						}
						
						if (!is_numeric($key)) {
							// Validate E-mail/Name combination
							if ($this->modx->getOption('validateemails', $this->_config, true)) {
								if (!$this->_validate('email', $value)) {
									continue;
								}
							}
							
							$this->_cc[$value] = $key;
						} else {
							$this->_cc[] = $value;
						}
					}
				} else {
					// Validate e-mail
					if ($this->modx->getOption('validateemails', $this->_config, true)) {
						if (!$this->_validate('email', $email)) {
							break;
						}
					}
					
					if ($name != '') {
						$this->_cc[$email] = $name;
					} else {
						$this->_cc[] = $email;
					}
				}
				break;
			case 'bcc':
			case 'blindcarboncopy':
			case 'hidden':
				if (is_array($email)) {
					foreach($email as $key => $value) {
						// Overwrites the name on ALL inserted $emails
						if ($name != '') {
							$key = $name;
						}
						
						if (!is_numeric($key)) {
							// Validate E-mail/Name combination
							if ($this->modx->getOption('validateemails', $this->_config, true)) {
								if (!$this->_validate('email', $value)) {
									continue;
								}
							}
							
							$this->_bcc[$value] = $key;
						} else {
							$this->_bcc[] = $value;
						}
					}
				} else {
					// Validate e-mail
					if ($this->modx->getOption('validateemails', $this->_config, true)) {
						if (!$this->_validate('email', $email)) {
							break;
						}
					}

					if ($name != '') {
						$this->_bcc[$email] = $name;
					} else {
						$this->_bcc[] = $email;
					}
				}
				break;
			case 'from':
				// Use as an array when your e-mail has multiple senders
				if (is_array($email)) {
					foreach($email as $key => $value) {
						// Overwrites the name on ALL inserted $emails
						if ($name != '') {
							$key = $name;
						}
						
						// Validate E-mail/Name combination
						if ($this->modx->getOption('validateemails', $this->_config, true)) {
							if (!$this->_validate('email', $value)) {
								continue;
							}
						}
						
						if (!is_numeric($key)) {
							$this->_from[$value] = $key;
						} else {
							$this->_from[] = $value;
						}
					}
				} else {
					// Validate e-mail
					if ($this->modx->getOption('validateemails', $this->_config, true)) {
						if (!$this->_validate('email', $email)) {
							break;
						}
					}

					if ($name != '') {
						$this->_from[$email] = $name;
					} else {
						$this->_from[] = $email;
					}
				}
				break;
			case 'sender':
				return $this->sender($email);
				break;
			case 'returnpath':
			case 'bounce':
				return $this->returnPath($email);
				break;
			case 'readreceipt':
			case 'receipt':
				return $this->readReceipt($email);
				break;
			default:
				return false;
				break;
		}
		
		return $this->message;
	}
	
	/**
	 * subject
	 * 
	 * Sets the subject of the e-mail
	 * 
	 * @name	subject
	 * @access	public
	 * 
	 * @param	string	$subject	Subject of the e-mail (defaults to empty; not advisable)
	 * 
	 * @return	object	message object
	 */
	public function subject($subject = '') {
		if ($this->message == null) {
			return false;
		}
		
		$this->message->setSubject($subject);
		
		return $this->message;
	}
	
	/**
	 * sender
	 * 
	 * Sets the sender of the e-mail (does not appear in the GUI of your e-mail application)
	 * Used to identify the senter to the e-mail server. Also used as a default Return-Path (unless else specified)
	 * 
	 * @name	sender
	 * @access	public
	 * 
	 * @param	string	$email		Sender's e-mail (also a Return-Path)
	 * 
	 * @return	object	message object
	 */
	public function sender($email) {
		if ($this->message == null) {
			return false;
		}
		
		if ($this->modx->getOption('validateemails', $this->_config, true)) {
			if ($this->_validate('email', $email)) {
				$this->message->setSender($email);
			}
		}
		
		return $this->message;
	}
	
	/**
	 * returnPath
	 * 
	 * Sets the Return-Path (also known as bounce address)
	 * 
	 * @name	returnPath
	 * @access	public
	 * 
	 * @param	string	$email		The Return-Path (bounce) e-mail address
	 * 
	 * @return	object	message object
	 */
	public function returnPath($email) {
		if ($this->message == null) {
			return false;
		}
		
		if ($this->modx->getOption('validateemails', $this->_config, true)) {
			if ($this->_validate('email', $email)) {
				$this->message->setReturnPath($email);
			}
		}
		
		return $this->message;
	}
	
	/**
	 * @see returnPath
	 */
	public function bounce($email) {
		return $this->returnPath($email);
	}
	
	/**
	 * readReceipt
	 * 
	 * Sets the ReadReceipt e-mail (which will e-mail when opened)
	 * This is highly annoying and should not be used. I tell ye!
	 * 
	 * @name	readReceipt
	 * @access	public
	 * 
	 * @param	string	$email		The read receipt e-mail address
	 * 
	 * @return	object	message object
	 */
	public function readReceipt($email) {
		if ($this->message == null) {
			return false;
		}
		
		if ($this->modx->getOption('validateemails', $this->_config, true)) {
			if ($this->_validate('email', $email)) {
				$this->message->setReadReceiptTo($email);
			}
		}
		
		return $this->message;
	}
	
	/**
	 * @see readReceipt
	 */
	public function receipt($email) {
		return $this->readReceipt($email);
	}
	
	/**
	 * replyTo
	 * 
	 * Sets the Reply-To field. Used when replying to an e-mail.
	 * It's advised that you set up a catch-all e-mail address if you're using no-reply@domain.tld
	 * 
	 * @name	replyTo
	 * @access	public
	 * 
	 * @param	string	$email		The reply to address
	 * 
	 * @return	object	message object
	 */
	public function replyTo($email) {
		if ($this->message == null) {
			return false;
		}
		
		if ($this->modx->getOption('validateemails', $this->_config, true)) {
			if ($this->_validate('email', $email)) {
				$this->message->setReplyTo($email);
			}
		}
		
		return $this->message;
	}
	
	/**
	 * body
	 * 
	 * Sets the body of the message (for the additional, inline body, use @function plain)
	 * 
	 * @name	body
	 * @access	public
	 * 
	 * @param	string	$body		Body of the e-mail, yes, really.
	 * @param	string	$type		Content type of the e-mail (text/html or text/plain)
	 * @param	string	$charset	Charset of the e-mail, defaults to UTF-8 (prefered)
	 * @param	string	$encoding	Encoding of the e-mail, defaults to 8bit (prefered)
	 * 
	 * @return	object	message object
	 */
	public function body($body, $type = 'text/html', $charset = null, $encoding = null) {
		if ($this->message == null) {
			return false;
		}
		
		$charset = (($charset == null || $charset == '') ? $this->modx->getOption(self::MAIL_CHARSET, $this->attributes, 'UTF-8') : $charset);
		$encoding = (($encoding == null || $encoding == '') ? $this->modx->getOption(self::MAIL_ENCODING, $this->attributes, 'UTF-8') : $encoding);
		
		$this->message->setBody($body, $type, $charset, $encoding);
		
		return $this->message;
	}
	
	/**
	 * @see body
	 */
	public function message($body, $type = 'text/html', $charset = null, $encoding = null) {
		return $this->body($body, $type, $charset, $encoding);
	}
	
	/**
	 * plain
	 * 
	 * Sets the alternative part of the message (picked up by plain-supporting applications (such as the iPad/iPhone))
	 * 
	 * @name	body
	 * @access	plain
	 * 
	 * @param	string	$body		Body of the e-mail, yes, really.
	 * @param	string	$charset	Charset of the e-mail, defaults to UTF-8 (prefered)
	 * @param	string	$encoding	Encoding of the e-mail, defaults to 8bit (prefered)
	 * 
	 * @return	object	message object
	 */
	public function plain($body, $charset = null, $encoding = null) {
		$charset = (($charset == null || $charset == '') ? $this->modx->getOption(self::MAIL_CHARSET, $this->attributes, 'UTF-8') : $charset);
		$encoding = (($encoding == null || $encoding == '') ? $this->modx->getOption(self::MAIL_ENCODING, $this->attributes, '8bit') : $encoding);
		
		$this->message->addPart($body, 'text/plain', $charset, $encoding);
				
		return $this->message;
	}

	/**
	 * attach
	 * 
	 * Attaches a single attachment to the message
	 * 
	 * @name	attach
	 * @access	public
	 * 
	 * @param	string	$filepath	The path to the file (must be absolute)
	 * @param	string	$name		The replacement name (with extension, defaults to the file' filename (from filepath))
	 * @param	string	$type		The type of the attachment (defaults to the file' filetype)
	 * @params	string	$attach		The type of attachment (inline, attachment)	
	 * 
	 * @return	object	message object
	 */
	public function attach($filepath, $name = null, $type = null, $attach = 'attachment') {
		$attachment = Swift_Attachment::fromPath($filepath, $type);
		
		$attachment->setDisposition($attach);
		
		if ($name != null && $name != '') {
			$attachment->setFilename($name);
		}
		
		$this->message->attach($attachment);
		unset($attachment);
		
		return $this->message;
	}

    /**
	 * clearAttachments
	 * 
	 * Swift Mailer does not have a clearAttachments, anonymous for backwards compatibility with PHPMailer
	 * 
	 * @name	clearAttachments
	 * @access	public
	 * 
	 * @deprecated
	 * 
	 * @return	object	message object
	 */
    public function clearAttachments() {
		return $this->message;
    }
	
	/**
	 * prority
	 * 
	 * Sets the mail's priority. This is not supported by webapps like Google's Gmail.
	 * Nowadays, it is supported spotty at best
	 * 
	 * @name	prority
	 * @access	public
	 * 
	 * @param	mixed	$priority	Priority of the e-mail
	 * 
	 * @return	void
	 */
	public function priority($prority = 3) {
		switch($priority) {
			case 'highest':
			case 1:
				$this->message->setPriority(1);
				break;
			case 'high':
			case 2:
				$this->message->setPriority(2);
				break;
			case 'normal':
			case 3:
				$this->message->setPriority(3);
				break;
			case 'low':
			case 4:
				$this->message->setPriority(4);
				break;
			case 'lowest':
			case 5:
				$this->message->setPriority(5);
				break;
			default:
				$this->message->setPriority(3);
				break;
		}
		
		return $this->message;
	}
	
	/**
	 * header
	 * 
	 * Attaches custom X-headers to the message (rendered upon send)
	 * 
	 * @name	header
	 * @access	public
	 * 
	 * @param	string	$header		The header key to insert (automaticly prefixed by X-)
	 * @param	string	$value		The value to insert
	 * @param	array	$parameters	Parameters to be suffixed after the value (e.g: X-Engine: Swift Mailer; param;value)
	 * @param	bool	$override	Overrides the value if already set (defaults to true, false to skip if present)
	 * 
	 * @return	object	message object
	 */
	public function header($header, $value, $parameters = array(), $override = true) {
		$header = str_replace(' ', '-', $header);
		$reserved = array('engine', 'package');
		
		if (preg_match('%('.implode('|', $reserved).')%i', $header)) {
			$this->modx->log(1, 'modSwiftMailer - Trying to insert reserved header');
			return false;
		}
		
		if ($override == false && isset($this->_headers[$header])) {
			return false;
		}
		
		$this->_headers[$header] = array(
			'value' => $value,
			'parameters' => $parameters
		);
		
		return true;
	}

    /**
	 * send
	 * 
	 * Loads (and returns) the mailer object. 
	 * The mailer object is a combination of a transport, message, preferences and header object
	 * 
	 * Unfortunately all the error handeling has to be done by MODX since Swift Mailer chose the easy way out with
	 * PHP Exceptions instead of making this configurable with a development constant. MODX whips ya arsch.
	 * 
	 * @name	send
	 * @access	public
	 * 
	 * @return	array		Status array; 'success' is always available, other per status (true:sent, false:failures)
	 */
    public function send() {
		// Create a new transport (moved from _getMailer() due to overriding SMTP settings per transport)
		$this->mailer = Swift_Mailer::newInstance($this->_transport);
		
		// Initialize the logger class
		if ($this->getDebug() == true) {
			$this->_logger = new Swift_Plugins_Loggers_ArrayLogger();
			$this->mailer->registerPlugin(new Swift_Plugins_LoggerPlugin($this->_logger));
		}
		
		foreach($this->_plugins as $plugin => $config) {
			if (!isset($config['active']) || $config['active'] !== true) {
				continue;
			}
			
			switch($plugin) {
				case 'decorator':
					$this->mailer->registerPlugin(new Swift_Plugins_DecoratorPlugin($config));
					break;
				case 'antiflood':
					$this->mailer->registerPlugin(new Swift_Plugins_AntiFlood($config['threshold'], $config['sleep']));
					break;
				case 'throttler':
					switch($config['mode']) {
						case 'bytes':
							$this->mailer->registerPlugin(new Swift_Plugins_ThrottlerPlugin($config['rate'], Swift_Plugins_ThrottlerPlugin::BYTES_PER_MINUTE));
							break;
						default:
						case 'messages':
							$this->mailer->registerPlugin(new Swift_Plugins_ThrottlerPlugin($config['rate'], Swift_Plugins_ThrottlerPlugin::MESSAGES_PER_MINUTE));
							break;
					}
					break;
			}
		}
			
		if ($this->message == null || $this->mailer == null) {
			if ($this->message == null) {
				$this->modx->log(1, 'modSwiftMailer is missing a valid message object');
			}
			
			if ($this->mailer == null) {
				$this->modx->log(1, 'modSwiftMailer is missing a valid mailer object');
			}
			
			return $this->modx->error->failure('modSwiftMailer is missing a valid message and/or mailer object');
		}
		
        $failures = array();
		
		if (empty($this->_to) && empty($this->_cc) && empty($this->_bcc)) {
			return $this->modx->error->failure('modSwiftMailer could not send e-mail without any recipients (to, cc or bcc)');
		}
		
		$this->message->setTo($this->_to);
		$this->message->setCc($this->_cc);
		$this->message->setBcc($this->_bcc);
		$this->message->setFrom($this->_from);
		
		$returnPath = $this->message->getReturnPath();
		
		if (empty($returnPath)) {
			$this->modx->log(1, 'modSwiftMailer is missing a Return-Path');
			
			$sender = $this->message->getSender();
			$from = $this->message->getFrom();
			
			if (!empty($sender)) {
				if (is_array($sender)) {
					$sender = reset($sender);
					
					if (!is_numeric(key($sender))) {
						$this->message->setReturnPath(key($sender));
					} else {
						$this->message->setReturnPath(reset($sender));
					}
				} else if (is_string($sender)) {
					$this->message->setReturnPath($sender);
				}
			} else if (!empty($from)) {
				$this->modx->log(1, 'modSwiftMailer is missing a sender');
				
				if (is_array($from)) {
					$sender = reset($from);
					
					if (!is_numeric(key($from))) {
						$this->message->setReturnPath(key($from));
						$this->message->setSender(key($from));
					} else {
						$this->message->setReturnPath(reset($from));
						$this->message->setSender(reset($from));
					}
				} else if (is_string($from)) {
					$this->message->setSender($from);
					$this->message->setReturnPath($from);
				}
			} else {
				$this->modx->log(1, 'modSwiftMailer is missing a Return-Path, Sender AND From alias, cancelling mail');
				
				return $this->modx->error->failure('modSwiftMailer is missing a return path, sender and from');
			}
			
			unset($sender, $from);
		}
		
		$headers = $this->message->getHeaders();
		
		$headers->addParameterizedHeader('X-Engine', 'Swift Mailer for MODX', array(
			'author' => 'Mark Ernst',
			'version' => self::major.'.'.self::minor.'.'.self::patch.'-'.self::release.self::index
		));
		
		foreach($this->_headers as $header => $value) {
			if (empty($value['parameters'])) {
				$headers->addTextHeader('X-'.$header, $value['value']);
			} else {
				$headers->addParameterizedHeader('X-'.$header, $value['value'], $value['parameters']);
			}
		}
		
		$sent = $this->mailer->send($this->message, $failures);
		
		// Auto reset after sending
		if ($this->modx->getOption('autoreset', $this->_config, true)) {
			$this->reset();
		}
		
		if (!empty($failures)) {
			return array(
				'success' => (($send == count($failures)) ? false : true),
				'message' => $sent.' mails sent, '.count($failures).' failed dilveries',
				'sent' => $sent,
				'failures' => $failures
			);
		}
		
		return array(
			'success' => true,
			'sent' => $sent
		);
    }
	
	/**
	 * @see send
	 */
	public function mail() {
		return $this->send();
	}
	
	/**
	 * reset
	 * 
	 * Swift Mailer automaticly clears to, cc, bcc and other fields when re-set but this is simply to catch the
	 * existing functionality left in place by modMail and modPHPMailer. Also removes and resets headers, which is
	 * something that Swift Mailer doesn't do automaticly.
	 * 
	 * @name	reset
	 * @access	public
	 * 
	 * @return	object	message object
	 */
    public function reset($headers = true) {
		$this->_to = array();
		$this->_cc = array();
		$this->_bcc = array();
		
		$this->message->setTo(array());
		$this->message->setCc(array());
		$this->message->setBcc(array());
		$this->message->setSubject('');
		
		if ($headers == true) {
			$this->resetHeaders();
		}
		
		return $this->message;
    }
	
	/**
	 * resetHeaders
	 * 
	 * Resets any dynamically added headers (X-headers)
	 * 
	 * @name	resetHeaders
	 * @access	public
	 * 
	 * @return	void
	 */
	public function resetHeaders() {
		$headers = $this->message->getHeaders();
		
		foreach(array_keys($this->_headers) as $header) {
			$headers->removeAll('X-'.$header);
		}
	}
	
	/**
	 * plugin
	 * 
	 * Registers compatible plugins with the Mailer object
	 * 
	 * @name	plugin
	 * @access	public
	 * 
	 * @param	string	$plugin		The title of the plugin
	 * @param	array	$config		(optional) Configuration
	 * 
	 * @return	void
	 */
	public function plugin($type, $config = array()) {
		if (!isset($this->_plugins[$type])) {
			return false;
		}
		
		// Overwrite the plugin since all plugins can only be used once
		$this->_plugins[$type] = array_merge($this->_plugins[$type], $config);
	}

	/**
	 * _getMailer
	 * 
	 * Loads (and returns) the mailer object. 
	 * The mailer object is a combination of a transport, message, preferences and header object
	 * 
	 * @name	_getMailer
	 * @access	protected
	 * 
	 * @param	array	$attributes	(optional) Override attributes
	 * 
	 * @return	object	mailer object
	 */
	protected function _getMailer($attributes = array()) {
		if (!$this->mailer) {
			if (!require_once($this->modx->getOption('core_path').'model/modx/mail/swiftmailer/model/swiftmailer/swift_required.php')) {
				return false;
			}
			
			// Set the default attributes
			$this->set(self::MAIL_CHARSET, $this->modx->getOption(self::MAIL_CHARSET, $this->attributes, 'UTF-8'));
			$this->set(self::MAIL_ENCODING, $this->modx->getOption(self::MAIL_ENCODING, $this->attributes, '8bit'));
			$this->set(self::MAIL_CONTENT_TYPE, $this->modx->getOption(self::MAIL_CONTENT_TYPE, $this->attributes, 'text/html'));
			
			// Override attributes
			foreach($attributes as $attribute => $value) {
				$this->set($attribute, $value);
			}
			
			if ($this->get(self::MAIL_ENGINE) == null) {
				$this->set(self::MAIL_ENGINE, 'default');
			}
			
			switch($this->get(self::MAIL_ENGINE)) {
				case 'default':
					$this->_transport = Swift_MailTransport::newInstance();
					break;
				case 'sendmail':
					$this->set(self::MAIL_ENGINE_PATH, $this->modx->getOption(self::MAIL_ENGINE_PATH, $this->attributes, '/usr/sbin/sendmail -bs'));
					
					$this->_transport = Swift_SendmailTransport::newInstance($this->get(self::MAIL_ENGINE_PATH));
					break;
				case 'smtp':
					if (intval($this->modx->getOption('mail_use_smtp')) != 1) {
						return false;
					}
					
					$this->_transport = Swift_SmtpTransport::newInstance();

					// Define the SMTP host and port
					$this->_transport->setHost($this->get(self::MAIL_SMTP_HOSTS));		
					$this->_transport->setPort($this->get(self::MAIL_SMTP_PORT));

					// Ignore MAIL_SMTP_AUTH since authentication always required a username and password
					if ($this->get(self::MAIL_SMTP_USER) != '' && $this->get(self::MAIL_SMTP_PASS) != '') {
						$this->_transport->setUsername($this->get(self::MAIL_SMTP_USER));
						$this->_transport->setPassword($this->get(self::MAIL_SMTP_PASS));
					}

					$this->_transport->setTimeout($this->get(self::MAIL_SMTP_TIMEOUT));
					break;
			}
			
			// Create a new message object (used to create the initial e-mail)
			$this->message = Swift_Message::newInstance();
			$this->message->setMaxLineLength(1000); // @see (Google) RFC 2822
			$this->message->setPriority(3); // @see priority
			
			// Extend (and modify) the preferences to the class to be used more easily
			$this->_preferences = Swift_Preferences::getInstance();
			$this->_preferences->setCharset($this->get(self::MAIL_CHARSET));
			$this->_preferences->setTempDir($this->modx->getOption('cache_path').'swiftmailer/');
			$this->_preferences->setCacheType($this->modx->getOption('mail_cache_type', null, 'disk'));
		}
	}
	
	/**
	 * debug
	 * 
	 * Enables or disables the debug state
	 * 
	 * @name	debug
	 * @access	public
	 * 
	 * @param	bool	$enable		Debug state
	 * 
	 * @return	void
	 */
	public function debug($enable = false) {
		$this->_debug = $enable;
	}
	
	/**
	 * getDebug
	 * 
	 * Returns the class' current debug state
	 * 
	 * @name	getDebug
	 * @access	public
	 * 
	 * @return	bool	Debug state
	 */
	public function getDebug() {
		return $this->_debug;
	}
	
	/**
	 * getLog
	 * 
	 * Returns the logger dump (usually used after @see send)
	 * 
	 * @name	getLog
	 * @access	public
	 * 
	 * @return	mixed	ArrayLogger output
	 */
	public function getLog() {
		return $this->_logger->dump();
	}
	
	/**
	 * _validate
	 * 
	 * Validates any type of supported input and caches it
	 * 
	 * @name	_validate
	 * @access	public
	 * 
	 * @param	string	$type		Type of validation to perform on input
	 * @param	mixed	$input		The relative input to validate
	 * @param	bool	$cache		(optional) To cache the result, defaults to true
	 * 
	 * @return	mixed	ArrayLogger output
	 */
	private function _validate($type, $input, $cache = true) {
		static $stack = array();
		$result = null;
		
		if (isset($stack[$type][$input]) && $cache == true) {
			return $stack[$type][$input];
		}
		
		switch($type) {
			case 'email':
				if (!isset($stack[$type][$input])) {
					$result = preg_match('/^[a-zA-Z0-9&\'\.\-_\+]+\@[a-zA-Z0-9.-]+\.+[a-zA-Z]{2,6}$/', $input);
				}
		}
		
		if ($cache == true && $result !== null) {
			$stack[$type][$input] = (bool) ((is_int($result) || is_numeric($result)) ? intval($result) : $result);
		}
		
		return $stack[$type][$input];
	}
}
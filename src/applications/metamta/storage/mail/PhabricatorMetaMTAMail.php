<?php

/*
 * Copyright 2011 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * See #394445 for an explanation of why this thing even exists.
 */
class PhabricatorMetaMTAMail extends PhabricatorMetaMTADAO {

  const STATUS_QUEUE = 'queued';
  const STATUS_SENT  = 'sent';
  const STATUS_FAIL  = 'fail';

  const MAX_RETRIES   = 250;
  const RETRY_DELAY   = 5;

  protected $parameters;
  protected $status;
  protected $message;
  protected $retryCount;
  protected $nextRetry;
  protected $relatedPHID;

  public function __construct() {

    $this->status     = self::STATUS_QUEUE;
    $this->retryCount = 0;
    $this->nextRetry  = time();
    $this->parameters = array();

    parent::__construct();
  }

  public function getConfiguration() {
    return array(
      self::CONFIG_SERIALIZATION => array(
        'parameters'  => self::SERIALIZATION_JSON,
      ),
    ) + parent::getConfiguration();
  }

  protected function setParam($param, $value) {
    $this->parameters[$param] = $value;
    return $this;
  }

  protected function getParam($param) {
    return idx($this->parameters, $param);
  }

  public function getSubject() {
    return $this->getParam('subject');
  }

  public function addTos(array $phids) {
    $this->setParam('to', $phids);
    return $this;
  }

  public function addCCs(array $phids) {
    $this->setParam('cc', $phids);
    return $this;
  }

  public function addHeader($name, $value) {
    $this->parameters['headers'][$name] = $value;
    return $this;
  }

  public function setFrom($from) {
    $this->setParam('from', $from);
    return $this;
  }

  public function setReplyTo($reply_to) {
    $this->setParam('reply-to', $reply_to);
    return $this;
  }

  public function setSubject($subject) {
    $this->setParam('subject', $subject);
    return $this;
  }

  public function setBody($body) {
    $this->setParam('body', $body);
    return $this;
  }

  public function setIsHTML($html) {
    $this->setParam('is-html', $html);
    return $this;
  }

  public function getSimulatedFailureCount() {
    return nonempty($this->getParam('simulated-failures'), 0);
  }

  public function setSimulatedFailureCount($count) {
    $this->setParam('simulated-failures', $count);
    return $this;
  }

  /**
   * Use this method to set an ID used for message threading. MetaMTA will
   * set appropriate headers (Message-ID, In-Reply-To, References and
   * Thread-Index) based on the capabilities of the underlying mailer.
   *
   * @param string  Unique identifier, appropriate for use in a Message-ID,
   *                In-Reply-To or References headers.
   * @param bool    If true, indicates this is the first message in the thread.
   * @return this
   */
  public function setThreadID($thread_id, $is_first_message = false) {
    $this->setParam('thread-id', $thread_id);
    $this->setParam('is-first-message', $is_first_message);
    return $this;
  }

  /**
   * Save a newly created mail to the database and attempt to send it
   * immediately if the server is configured for immediate sends. When
   * applications generate new mail they should generally use this method to
   * deliver it. If the server doesn't use immediate sends, this has the same
   * effect as calling save(): the mail will eventually be delivered by the
   * MetaMTA daemon.
   *
   * @return this
   */
  public function saveAndSend() {
    $ret = $this->save();

    if (PhabricatorEnv::getEnvConfig('metamta.send-immediately')) {
      $this->sendNow();
    }

    return $ret;
  }


  public function buildDefaultMailer() {
    $class_name = PhabricatorEnv::getEnvConfig('metamta.mail-adapter');
    PhutilSymbolLoader::loadClass($class_name);
    return newv($class_name, array());
  }

  /**
   * Attempt to deliver an email immediately, in this process.
   *
   * @param bool  Try to deliver this email even if it has already been
   *              delivered or is in backoff after a failed delivery attempt.
   * @param PhabricatorMailImplementationAdapter Use a specific mail adapter,
   *              instead of the default.
   *
   * @return void
   */
  public function sendNow(
    $force_send = false,
    PhabricatorMailImplementationAdapter $mailer = null) {

    if ($mailer === null) {
      $mailer = $this->buildDefaultMailer();
    }

    if (!$force_send) {
      if ($this->getStatus() != self::STATUS_QUEUE) {
        throw new Exception("Trying to send an already-sent mail!");
      }

      if (time() < $this->getNextRetry()) {
        throw new Exception("Trying to send an email before next retry!");
      }
    }

    try {
      $parameters = $this->parameters;
      $phids = array();
      foreach ($parameters as $key => $value) {
        switch ($key) {
          case 'from':
          case 'to':
          case 'cc':
            if (!is_array($value)) {
              $value = array($value);
            }
            foreach (array_filter($value) as $phid) {
              $phids[] = $phid;
            }
            break;
        }
      }

      $handles = id(new PhabricatorObjectHandleData($phids))
        ->loadHandles();

      $params = $this->parameters;
      $default = PhabricatorEnv::getEnvConfig('metamta.default-address');
      if (empty($params['from'])) {
        $mailer->setFrom($default);
      } else if (!PhabricatorEnv::getEnvConfig('metamta.can-send-as-user')) {
        $from = $params['from'];
        $handle = $handles[$from];
        if (empty($params['reply-to'])) {
          $params['reply-to'] = $handle->getEmail();
          $params['reply-to-name'] = $handle->getFullName();
        }
        $mailer->setFrom(
          $default,
          $handle->getFullName());
        unset($params['from']);
      }

      $is_first = !empty($params['is-first-message']);
      unset($params['is-first-message']);

      $reply_to_name = idx($params, 'reply-to-name', '');
      unset($params['reply-to-name']);

      foreach ($params as $key => $value) {
        switch ($key) {
          case 'from':
            $mailer->setFrom($handles[$value]->getEmail());
            break;
          case 'reply-to':
            $mailer->addReplyTo($value, $reply_to_name);
            break;
          case 'to':
            $emails = array();
            foreach ($value as $phid) {
              $emails[] = $handles[$phid]->getEmail();
            }
            $mailer->addTos($emails);
            break;
          case 'cc':
            $emails = array();
            foreach ($value as $phid) {
              $emails[] = $handles[$phid]->getEmail();
            }
            $mailer->addCCs($emails);
            break;
          case 'headers':
            foreach ($value as $header_key => $header_value) {
              $mailer->addHeader($header_key, $header_value);
            }
            break;
          case 'body':
            $mailer->setBody($value);
            break;
          case 'subject':
            $mailer->setSubject($value);
            break;
          case 'is-html':
            if ($value) {
              $mailer->setIsHTML(true);
            }
            break;
          case 'thread-id':
            if ($is_first && $mailer->supportsMessageIDHeader()) {
              $mailer->addHeader('Message-ID',  $value);
            } else {
              $mailer->addHeader('In-Reply-To', $value);
              $mailer->addHeader('References',  $value);
            }
            $thread_index = $this->generateThreadIndex($value, $is_first);
            $mailer->addHeader('Thread-Index', $thread_index);
            break;
          default:
            // Just discard.
        }
      }

      $mailer->addHeader('X-Mail-Transport-Agent', 'MetaMTA');

    } catch (Exception $ex) {
      $this->setStatus(self::STATUS_FAIL);
      $this->setMessage($ex->getMessage());
      $this->save();
      return;
    }

    if ($this->getRetryCount() < $this->getSimulatedFailureCount()) {
      $ok = false;
      $error = 'Simulated failure.';
    } else {
      try {
        $ok = $mailer->send();
        $error = null;
      } catch (Exception $ex) {
        $ok = false;
        $error = $ex->getMessage()."\n".$ex->getTraceAsString();
      }
    }

    if (!$ok) {
      $this->setMessage($error);
      if ($this->getRetryCount() > self::MAX_RETRIES) {
        $this->setStatus(self::STATUS_FAIL);
      } else {
        $this->setRetryCount($this->getRetryCount() + 1);
        $next_retry = time() + ($this->getRetryCount() * self::RETRY_DELAY);
        $this->setNextRetry($next_retry);
      }
    } else {
      $this->setStatus(self::STATUS_SENT);
    }

    $this->save();
  }

  public static function getReadableStatus($status_code) {
    static $readable = array(
      self::STATUS_QUEUE => "Queued for Delivery",
      self::STATUS_FAIL  => "Delivery Failed",
      self::STATUS_SENT  => "Sent",
    );
    $status_code = coalesce($status_code, '?');
    return idx($readable, $status_code, $status_code);
  }

  private function generateThreadIndex($seed, $is_first_mail) {
    // When threading, Outlook ignores the 'References' and 'In-Reply-To'
    // headers that most clients use. Instead, it uses a custom 'Thread-Index'
    // header. The format of this header is something like this (from
    // camel-exchange-folder.c in Evolution Exchange):

    /* A new post to a folder gets a 27-byte-long thread index. (The value
     * is apparently unique but meaningless.) Each reply to a post gets a
     * 32-byte-long thread index whose first 27 bytes are the same as the
     * parent's thread index. Each reply to any of those gets a
     * 37-byte-long thread index, etc. The Thread-Index header contains a
     * base64 representation of this value.
     */

    // The specific implementation uses a 27-byte header for the first email
    // a recipient receives, and a random 5-byte suffix (32 bytes total)
    // thereafter. This means that all the replies are (incorrectly) siblings,
    // but it would be very difficult to keep track of the entire tree and this
    // gets us reasonable client behavior.

    $base = substr(md5($seed), 0, 27);
    if (!$is_first_mail) {
      // Not totally sure, but it seems like outlook orders replies by
      // thread-index rather than timestamp, so to get these to show up in the
      // right order we use the time as the last 4 bytes.
      $base .= ' '.pack('N', time());
    }

    return base64_encode($base);
  }

}

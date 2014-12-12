<?php
/**
 * Copyright 2014 Facebook, Inc.
 *
 * You are hereby granted a non-exclusive, worldwide, royalty-free license to
 * use, copy, modify, and distribute this software in source code or binary
 * form for use in connection with the web services and APIs provided by
 * Facebook.
 *
 * As with any software that integrates with the Facebook platform, your use
 * of this software is subject to the Facebook Developer Principles and
 * Policies [http://developers.facebook.com/policy/]. This copyright notice
 * shall be included in all copies or substantial portions of the software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL
 * THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER
 * DEALINGS IN THE SOFTWARE.
 *
 */
namespace Facebook\Helpers;

use Facebook\Facebook;
use Facebook\Entities\AccessToken;
use Facebook\Entities\FacebookApp;
use Facebook\Url\UrlDetectionInterface;
use Facebook\Url\FacebookUrlDetectionHandler;
use Facebook\Url\FacebookUrlManipulator;
use Facebook\PersistentData\PersistentDataInterface;
use Facebook\PersistentData\FacebookSessionPersistentDataHandler;
use Facebook\Exceptions\FacebookSDKException;
use Facebook\FacebookClient;

/**
 * Class FacebookRedirectLoginHelper
 * @package Facebook
 * @author Fosco Marotto <fjm@fb.com>
 * @author David Poll <depoll@fb.com>
 */
class FacebookRedirectLoginHelper
{

  /**
   * @var FacebookApp The FacebookApp entity.
   */
  protected $app;

  /**
   * @var UrlDetectionInterface The URL detection handler.
   */
  protected $urlDetectionHandler;

  /**
   * @var PersistentDataInterface The persistent data handler.
   */
  protected $persistentDataHandler;

  /**
   * Constructs a RedirectLoginHelper for a given appId.
   *
   * @param FacebookApp $app The FacebookApp entity.
   * @param PersistentDataInterface|null $persistentDataHandler The persistent data handler.
   * @param UrlDetectionInterface|null $urlHandler The URL detection handler.
   */
  public function __construct(FacebookApp $app,
                              PersistentDataInterface $persistentDataHandler = null,
                              UrlDetectionInterface $urlHandler = null)
  {
    $this->app = $app;
    $this->persistentDataHandler = $persistentDataHandler ?: new FacebookSessionPersistentDataHandler();
    $this->urlDetectionHandler = $urlHandler ?: new FacebookUrlDetectionHandler();
  }

  /**
   * Returns the persistent data handler.
   *
   * @return PersistentDataInterface
   */
  public function getPersistentDataHandler()
  {
    return $this->persistentDataHandler;
  }

  /**
   * Returns the URL detection handler.
   *
   * @return UrlDetectionInterface
   */
  public function getUrlDetectionHandler()
  {
    return $this->urlDetectionHandler;
  }

  /**
   * Stores CSRF state and returns a URL to which the user should be sent to
   *   in order to continue the login process with Facebook.  The
   *   provided redirectUrl should invoke the handleRedirect method.
   *   If a previous request to certain permission(s) was declined
   *   by the user, rerequest should be set to true or the permission(s)
   *   will not be re-asked.
   *
   * @param string $redirectUrl The URL Facebook should redirect users to
   *                            after login.
   * @param array $scope List of permissions to request during login.
   * @param boolean $rerequest Toggle for this authentication to be a rerequest.
   * @param string $version Optional Graph API version if not default (v2.0).
   * @param string $separator The separator to use in http_build_query().
   *
   * @return string
   */
  public function getLoginUrl($redirectUrl,
                              array $scope = [],
                              $rerequest = false,
                              $version = null,
                              $separator = '&')
  {
    $version = $version ?: Facebook::DEFAULT_GRAPH_VERSION;
    $state = $this->generateState();
    $this->persistentDataHandler->set('state', $state);
    $params = [
      'client_id' => $this->app->getId(),
      'redirect_uri' => $redirectUrl,
      'state' => $state,
      'response_type' => 'code',
      'sdk' => 'php-sdk-' . Facebook::VERSION,
      'scope' => implode(',', $scope)
    ];

    if ($rerequest) {
      $params['auth_type'] = 'rerequest';
    }

    return 'https://www.facebook.com/' . $version . '/dialog/oauth?' .
      http_build_query($params, null, $separator);
  }

  /**
   * Returns the URL to send the user in order to log out of Facebook.
   *
   * @param AccessToken|string $accessToken The access token that will be logged out.
   * @param string $next The url Facebook should redirect the user to after
   *                          a successful logout.
   * @param string $separator The separator to use in http_build_query().
   *
   * @return string
   */
  public function getLogoutUrl($accessToken, $next, $separator = '&')
  {
    $params = [
      'next' => $next,
      'access_token' => (string) $accessToken,
    ];
    return 'https://www.facebook.com/logout.php?' . http_build_query($params, null, $separator);
  }

  /**
   * Takes a valid code from a login redirect, and returns an AccessToken entity.
   *
   * @param FacebookClient $client The Facebook client.
   * @param string|null $redirectUrl The redirect URL.
   *
   * @return AccessToken|null
   *
   * @throws FacebookSDKException
   */
  public function getAccessToken(FacebookClient $client, $redirectUrl = null)
  {
    if ( ! $code = $this->getCode()) {
      return null;
    }

    $this->validateCsrf();

    $redirectUrl = $redirectUrl ?: $this->urlDetectionHandler->getCurrentUrl();
    // At minimum we need to remove the state param
    $redirectUrl = FacebookUrlManipulator::removeParamsFromUrl($redirectUrl, ['state']);

    return AccessToken::getAccessTokenFromCode($code, $this->app, $client, $redirectUrl);
  }

  /**
   * Validate the request against a cross-site request forgery.
   *
   * @throws FacebookSDKException
   */
  protected function validateCsrf()
  {
    $state = $this->getState();
    $savedState = $this->persistentDataHandler->get('state');

    if ( ! $state || ! $savedState) {
      throw new FacebookSDKException(
        'Cross-site request forgery validation failed. ' .
        'Required param "state" missing.'
      );
    }
    if ($state !== $savedState) {
      throw new FacebookSDKException(
        'Cross-site request forgery validation failed. ' .
        'The "state" param from the URL and session do not match.'
      );
    }
  }

  /**
   * Return the code.
   *
   * @return string|null
   */
  protected function getCode()
  {
    return $this->getInput('code');
  }

  /**
   * Return the state.
   *
   * @return string|null
   */
  protected function getState()
  {
    return $this->getInput('state');
  }

  /**
   * Return the error code.
   *
   * @return string|null
   */
  public function getErrorCode()
  {
    return $this->getInput('error_code');
  }

  /**
   * Returns the error.
   *
   * @return string|null
   */
  public function getError()
  {
    return $this->getInput('error');
  }

  /**
   * Returns the error reason.
   *
   * @return string|null
   */
  public function getErrorReason()
  {
    return $this->getInput('error_reason');
  }

  /**
   * Returns the error description.
   *
   * @return string|null
   */
  public function getErrorDescription()
  {
    return $this->getInput('error_description');
  }

  /**
   * Returns a value from a GET param.
   *
   * @param string $key
   *
   * @return string|null
   */
  private function getInput($key)
  {
    return isset($_GET[$key]) ? $_GET[$key] : null;
  }

  /**
   * Generate a state string for CSRF protection.
   *
   * @return string
   */
  protected function generateState()
  {
    return $this->random(16);
  }

  /**
   * Generate a cryptographically secure pseudrandom number.
   * 
   * @param int $bytes Number of bytes to return.
   * 
   * @return string
   * 
   * @throws FacebookSDKException
   * 
   * @TODO Add support for Windows platforms.
   */
  private function random($bytes)
  {
    if (!is_numeric($bytes)) {
      throw new FacebookSDKException(
        "random() expects an integer"
      );
    }
    if ($bytes < 1) {
      throw new FacebookSDKException(
        "random() expects an integer greater than zero"
      );
    }
    $buf = '';
    // http://sockpuppet.org/blog/2014/02/25/safely-generate-random-numbers/
    if (!ini_get('open_basedir')
      && is_readable('/dev/urandom')) {
      $fp = fopen('/dev/urandom', 'rb');
      if ($fp !== FALSE) {
        $buf = fread($fp, $bytes);
        fclose($fp);
        if($buf !== FALSE) {
          return bin2hex($buf);
        }
      }
    }

    if (function_exists('mcrypt_create_iv')) {
        $buf = mcrypt_create_iv($bytes, MCRYPT_DEV_URANDOM);
        if ($buf !== FALSE) {
          return bin2hex($buf);
        }
    }
    
    while (strlen($buf) < $bytes) {
      $buf .= md5(uniqid(mt_rand(), true), true); 
      // We are appending raw binary
    }
    return bin2hex(substr($buf, 0, $bytes));
  }

}
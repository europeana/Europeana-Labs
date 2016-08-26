<?php
// Twig extension for the apikey signup form

namespace Bolt\Extension\Europeana\ApiKeyHelper;

use Bolt\BaseExtension;
use Symfony\Component\HttpFoundation\Request;
use GuzzleHttp;
//use Symfony\Component\HttpFoundation\Response;

class Extension extends BaseExtension
{
    /** @var string Extension name */
    const NAME = 'ApiKey Helper';
    /** @var string Extension's service container */
    const CONTAINER = 'extensions.ApiKeyHelper';
    private $posted = false;
    private $valid_input = false;
    private $form_errors = array();

    /**
     * Provide default Extension Name
     */
    public function getName()
    {
        return Extension::NAME;
    }

    /**
     * Allow users to place {{ apikeyform() }} tags into content, if
     * `allowtwig: true` is set in the contenttype.
     *
     * @return boolean
     */
    public function isSafe()
    {
        return true;
    }

    public function initialize()
    {
        /* Only in frontend */
        if ($this->app['config']->getWhichEnd() === 'frontend') {
            //$this->addSnippet('startofhead', '<!-- showApiKeyForm seems to be working -->');
            $this->addTwigFunction('apikeyform', 'showApiKeyForm');
        }
    }

    /**
     * Twig function to handle the form and the response
     * If the form is posted and correct,
     * a request to the API will be done in the background
     * while a thanks page will be displayed
     *
     * @return \Twig_Markup
     */
    public function showApiKeyForm($options=null)
    {
        $config = $this->config;
        $currenturl = $this->app['paths']['currenturl'];
        $template_form = $this->config['templates']['apikeyform'];
        $template_thanks = $this->config['templates']['thankspage'];

        dump($config);

        if($config['recaptcha']['enabled'] == true) {
            // Add javascript file for recaptcha
            $this->addJavascript(
                'https://www.google.com/recaptcha/api.js',
                ['late' => false, 'priority' => 1000]
            );
        }

        $this->app['twig.loader.filesystem']->addPath(__DIR__);

        $posted = $valid = false;

        dump($this->app['request']);

        if ($this->app['request']->isMethod('POST')) {
            $this->posted = true;
            $postvars = $this->app['request']->request->all();
            $this->validateInput($postvars);
        }

        if($this->posted && $this->valid_input) {
            // TODO: implement the new api when it is available
            //$temp = $this->dispatchRemoteRequestAuth();

            $temp = $this->dispatchRemoteRequest();
            //dump($temp);
            if($temp->success == true) {
                $html = $this->app['render']->render($template_thanks, array(
                    'config' => $config
                ));
            } else {
                $html = "<p>Something went wrong</p>";
                // TODO: remove the debugging stuff
                dump($temp);
            }
        } else {
            // add form error text to display
            if(!empty($this->form_errors)) {
                foreach($this->form_errors as $key => $value) {
                    $config['form']['fields'][$key]['error'] = $value;
                }
            }
            // keep entered values in display
            foreach($config['form']['fields'] as $key => $field) {
                if(!empty($postvars[$key])) {
                    $config['form']['fields'][$key]['postedValue'] = $postvars[$key];
                } else {
                    $config['form']['fields'][$key]['postedValue'] = null;
                }
            }
            $html = $this->app['render']->render($template_form, array(
                'config' => $config,
                'destination' => $currenturl,
                'fields' => $config['form']['fields'],
                'submit' => $config['form']['submit'],
            ));
        }

        return new \Twig_Markup($html, 'UTF-8');
    }

    /**
     * Check is all required fields are there
     *
     * @return boolean
     */
    protected function validateInput($postvars = array())
    {
        $has_errors = false;

        $config = $this->config;

        if($config['recaptcha']['enabled'] == true) {
            // request remote recaptcha
            $recaptcharesult = $this->dispatchRecaptchaRequest($postvars);
            dump($recaptcharesult);
        }

        foreach($config['form']['fields'] as $key => $field) {
            // test required fields
            if($field['required'] == true && empty($postvars[$key])) {
                $this->valid_input = false;
                $this->form_errors[$key] = str_replace('%label%', $field['label'], $field['placeholder']);
                $has_errors = true;
            }
            // do other tests - if you know what to do
        }

        if($has_errors) {
            $this->valid_input = false;
            return $this->valid_input;
        } else {
            $this->valid_input = true;
            return $this->valid_input;
        }
    }

    /**
     * Call the recaptcha remote request with curl
     *
     * @return mixed
     */
    protected function dispatchRecaptchaRequest($postvars)
    {
        dump($postvars);

        var $checkvars = [];
        $config = $this->config;

        $ch = curl_init();
        $request = $config['recaptcha']['remoteurl'];
        dump($request);

        $checkvars['secret'] = $config['recaptcha']['secret'];
        $checkvars['response'] = $postvars['g-recaptcha-response'];
        $checkvars['remoteip'] = $postvars['remoteip'];

        curl_setopt($ch, CURLOPT_URL,            $request);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1 );
        curl_setopt($ch, CURLOPT_HEADER,         false );
        curl_setopt($ch, CURLOPT_POST,           1 );
        curl_setopt($ch, CURLOPT_POSTFIELDS,     json_encode($postvars, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) );
        curl_setopt($ch, CURLOPT_HTTPHEADER,     array('Content-Type: application/json'));
        dump($ch);

        $returnvalue = curl_exec($ch);
        dump($returnvalue);

        return json_decode($returnvalue);
    }

    /**
     * Call the remote request with curl
     */
    protected function dispatchRemoteRequest()
    {
        $config = $this->config;

        $ch = curl_init();
        $request = 'http://'. $config['credentials']['fields']['j_username'] .':'. $config['credentials']['fields']['j_password'] .'@www.europeana.eu/api/admin/apikey';
        //dump($request);

        $postvars = $this->app['request']->request->all();
        //dump($postvars);

        curl_setopt($ch, CURLOPT_URL,            $request);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1 );
        curl_setopt($ch, CURLOPT_HEADER,         false );
        curl_setopt($ch, CURLOPT_POST,           1 );
        curl_setopt($ch, CURLOPT_POSTFIELDS,     json_encode($postvars, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) );
        curl_setopt($ch, CURLOPT_HTTPHEADER,     array('Content-Type: application/json'));
        //dump($ch);

        $returnvalue = curl_exec($ch);
        //dump($returnvalue);

        return json_decode($returnvalue);
    }

    /**
     * Call the remote request in multistep with guzzle
     */
    protected function dispatchRemoteRequestAuth()
    {
        $config = $this->config;

        // first login to the remote api
        $first_url = $config['credentials']['login_url'];
        $first_options = array(
            'fields' => $config['credentials']['fields'],
        );

        // debug some stuff
        echo 'requesting: '. $first_url;
        dump($first_options);
        $first_data = $this->fetchRemoteUrl($first_url, $first_options);

        // debug some stuff
        echo 'response from: '. $first_url;
        dump($first_data);

        // check if we are logged in
        // check if the cookie headers we need are there
        if(array_key_exists('JSESSIONID', $first_data['cookies'])) {
            $cookie_JSESSIONID = $first_data['cookies']['JSESSIONID'];
        } else {
            $cookie_JSESSIONID = false;
        }

        // do the second request
        if($cookie_JSESSIONID) {
            $postvars = $this->app['request']->request->all();

            // post the data
            $second_url = $config['credentials']['destination'];
            $second_options = array(
                'fields' => $postvars,
                'cookies' => $first_data['cookies'],
            );

            // debug some stuff
            echo 'requesting: '. $second_url;
            dump($second_options);

            $second_data = $this->fetchRemoteUrl($second_url, $second_options);

            // debug some stuff
            echo 'response from: '. $first_url;
            dump($second_data);
        }

        return true;
    }

    private function fetchRemoteUrl($url, $options = array())
    {
        $postoptions = array();
        $postoptions['body'] = $options['fields'];
        $postoptions['allow_redirects'] = false;

        try {
            $client = new GuzzleHttp\Client(['base_uri' => $this->config['credentials']['base_uri']]);

            $request = $client->createRequest('POST', $url, $postoptions);
            $request->addHeader('X-Test', 'testing');
            if(!empty($options['cookies']) && $options['cookies']['JSESSIONID']) {
                $request->addHeader('Cookie', 'JSESSIONID='.$options['cookies']['JSESSIONID']);
            }
            $response = $client->send($request);

            if($response) {
                $data['response'] = $response;
                $data['body'] = $response->getBody(true);
                $data['headers'] = $response->getHeaders();

                //$data['cookies'] = $response->getCookies();
                // do manual cookie stuff
                if(isset($data['headers']['Set-Cookie']) && is_array($data['headers']['Set-Cookie'])) {
                    $data['cookies'] = array();
                    foreach($data['headers']['Set-Cookie'] as $item) {
                        list($cookiekey, $cookievalue) = explode('=', explode('; ', $item)[0]);
                        $data['cookies'][$cookiekey] = $cookievalue;
                    }
                } else {
                    $data['cookies'] = false;
                }

                $this->app['logger.system']->info('Fetched remote resource: ' . $url, array('event' => 'ApiKeyHelper'));
            } else {
                $this->app['logger.system']->error('Empty response for: ' . $url, array('event' => 'ApiKeyHelper'));
            }
        } catch (GuzzleHttp\Exception\ClientException $e) {
            $response = $e->getResponse();
            if ($response && $response->getStatusCode() > 200) {
                $this->app['logger.system']->info('Got a ' . $response->getStatusCode() . ' header for ' . $url, array('event' => 'ApiKeyHelper'));
                dump('Got a ' . $response->getStatusCode() . ' header for ' . $url);
                return false;
            }
        }
        return $data;
    }

    /**
     * Set the defaults for configuration parameters
     *
     * @return array
     */
    protected function getDefaultConfig()
    {
        return array(
            'templates'=> array(
                'apikeyform' => 'assets/apikeyform.twig',
                'thankspage' => 'assets/thankspage.twig',
            ),
            'form' => array(
                'fields'      => array(
                    'email' => array(
                        'label' => 'Email address',
                        'placeholder' => 'Enter your email address',
                        'type' => 'email',
                        'classes' => '',
                        'required' => true,
                        'error' => '',
                    ),
                    'firstName' => array(
                        'label' => 'First Name',
                        'placeholder' => 'Enter your first name',
                        'type' => 'text',
                        'classes' => '',
                        'required' => true,
                        'error' => '',
                    ),
                    'lastName' => array(
                        'label' => 'Last Name',
                        'placeholder' => 'Enter your last name',
                        'type' => 'text',
                        'classes' => '',
                        'required' => true,
                        'error' => '',
                    ),
                    'company' => array(
                        'label' => 'Organisation',
                        'placeholder' => 'Please enter the organization name in English',
                        'type' => 'text',
                        'classes' => '',
                        'required' => false,
                        'error' => '',
                    ),
                ),
                'submit' => array(
                    'label' => 'Request key',
                ),
            ),
            'credentials' => array(
                'base_uri'  => 'http://europeana.eu/api/',
                'login_url' => 'http://europeana.eu/api/login.do',
                'destination' => 'http://europeana.eu/api/apikey',
                'fields' => array(
                    'j_username' => null,
                    'j_password' => null,
                ),
            ),
        );
    }
}

<?php
namespace DrestClient;

use DrestCommon\Error\ErrorException;
use DrestCommon\Representation\AbstractRepresentation;
use DrestCommon\Representation\RepresentationException;
use Guzzle\Http\Client as GuzzleClient;
use Guzzle\Http\Exception\BadResponseException;
use Psr\Log\LoggerInterface;
use Guzzle\Http\Message\Response;

class Client
{
    /**
     * The logger used to log request and response
     * @var Psr\Log\LoggerInterface 
     */
    protected $logger = null;
    /**
     * The transport to be used
     * @var GuzzleClient
     */
    protected $transport;

    /**
     * The data representation class to used when loading data
     * @var string $representationClass
     */
    protected $representationClass;

    /**
     * Cached error response class
     * Cleared whenever a new representation type is set
     * Redetermined whenever empty and handleErrorResponse() is called
     * @var string $errorResponseClass
     */
    protected $errorResponseClass;


    /**
     * Client constructor
     * @param string $endpoint The rest endpoint to be used
     * @param mixed $representation the data representation to use for all interactions - can be a string or a class
     * @throws \Exception
     */
    public function __construct($endpoint, $representation)
    {
        if (($endpoint = filter_var($endpoint, FILTER_VALIDATE_URL)) === false) {
            // @todo: create a an exception extension (ClientException)
            throw new \Exception('Invalid URL endpoint');
        }

        $this->setRepresentationClass($representation);
        $this->transport = new GuzzleClient($endpoint, array(
            'ssl.certificate_authority' => false
        ));
    }

    /**
     * The representation class to be used
     * @param mixed $representation
     * @throws \DrestCommon\Representation\RepresentationException
     */
    public function setRepresentationClass($representation)
    {
        $this->errorResponseClass = null;
        if (!is_object($representation)) {
            // Check if the class is namespaced, if so instantiate from root
            $className = (strstr($representation, '\\') !== false) ? '\\' . ltrim(
                    $representation,
                    '\\'
                ) : $representation;
            $className = (!class_exists($className)) ? '\\DrestCommon\\Representation\\' . ltrim(
                    $className,
                    '\\'
                ) : $className;
            if (!class_exists($className)) {
                throw RepresentationException::unknownRepresentationClass($representation);
            }
            $this->representationClass = $className;
        } elseif ($representation instanceof AbstractRepresentation) {
            $this->representationClass = get_class($representation);
        } else {
            throw RepresentationException::needRepresentationToUse();
        }
    }

    /**
     * Get an instance of representation class we interacting with
     * @return AbstractRepresentation $representation
     */
    protected function getRepresentationInstance()
    {
        return new $this->representationClass();
    }

    /**
     * Get data from a path
     * @param string $path - the path to be requested
     * @param array $headers - any additional headers you want to send on the request
     * @throws ErrorException
     * @return Response
     */
    public function get($path, array $headers = array())
    {
        $representation = $this->getRepresentationInstance();

        $headers['Accept'] = $representation->getContentType();
        $request = $this->transport->get(
            $path,
            $headers
        );

        try {
            $this->logRequest('GET', $request, $representation);
            $response = $this->transport->send($request);
            $this->logResponse('GET', $response);
        } catch (BadResponseException $exception) {
            $this->logError('GET', $exception);
            throw $this->handleErrorResponse($exception);
        }

        return $response->getBody(true);
    }

    /**
     * Post an object. You can optionally append variables to the path for posting (eg /users?sort=age).
     * @param string $path - the path to post this object to.
     * @param object $object - the object to be posted to given path
     * @param array $headers - an array of headers to send with the request
     * @return Response $response       - Response object with a populated representation instance
     * @throws ErrorException           - upon the return of any error document from the server
     */
    public function post($path, &$object, array $headers = array())
    {
        $representation = $this->getRepresentationInstance();
        $representation->update($object);

        $request = $this->transport->post(
            $path,
            $headers,
            $representation->__toString()
        );
        foreach ($this->getVarsFromPath($path) as $key => $value) {
            $request->setPostField($key, $value);
        }
        // Bug: Header must be set after adding post fields as Guzzle amends the Content-Type header info.
        // see: Guzzle\Http\Message\EntityEnclosingRequest::processPostFields()
        $request->setHeader('Content-Type', $representation->getContentType());

        try {
            $this->logRequest('POST', $request, $representation);
            $response = $this->transport->send($request);
            $this->logResponse('POST', $response);
        } catch (BadResponseException $exception) {
            $this->logError('POST', $exception);
            throw $this->handleErrorResponse($exception);
        }

        return $response->getBody(true);
    }

    /**
     * Put an object at a set location ($path)
     * @param string $path - the path to post this object to.
     * @param object $object - the object to be posted to given path
     * @param array $headers - an array of headers to send with the request
     * @return Response $response       - Response object with a populated representation instance
     * @throws ErrorException           - upon the return of any error document from the server
     */
    public function put($path, &$object, array $headers = array())
    {
        $representation = $this->getRepresentationInstance();
        $representation->update($object);

        $request = $this->transport->put(
            $path,
            $headers,
            $representation->__toString()
        );

        foreach ($this->getVarsFromPath($path) as $key => $value) {
            $request->setPostField($key, $value);
        }

        $request->setHeader('Content-Type', $representation->getContentType());

        try {
            $this->logRequest('PUT', $request, $representation);
            $response = $this->transport->send($request);
            $this->logResponse('PUT', $response);
        } catch (BadResponseException $exception) {
            $this->logError('PUT', $exception);
            throw $this->handleErrorResponse($exception);
        }

         return $response->getBody(true);
    }

    /**
     * Patch (partial update) an object at a set location ($path)
     * @param string $path the path to post this object to.
     * @param object $object the object to be posted to given path
     * @param array $headers an array of headers to send with the request
     * @return \DrestClient\Response $response Response object with a populated representation instance
     * @throws ErrorException upon the return of any error document from the server
     */
    public function patch($path, &$object, array $headers = array())
    {
        $representation = $this->getRepresentationInstance();
        $representation->update($object);

        $request = $this->transport->patch(
            $path,
            $headers,
            $representation->__toString()
        );

        foreach ($this->getVarsFromPath($path) as $key => $value) {
            $request->setPostField($key, $value);
        }

        $request->setHeader('Content-Type', $representation->getContentType());

        try {
            $this->logRequest('PATCH', $request, $representation);
            $response = $this->transport->send($request);
            $this->logResponse('PATCH', $response);
        } catch (BadResponseException $exception) {
            $this->logError('PATCH', $exception);
            throw $this->handleErrorResponse($exception);
        }

         return $response->getBody(true);
    }

    /**
     * Delete the passed object
     * @param string $path the path to post this object to.
     * @param array $headers an array of headers to send with the request
     */
    public function delete($path, array $headers = array())
    {
        $representation = $this->getRepresentationInstance();

        $headers['Accept'] = $representation->getContentType();
        $request = $this->transport->delete(
            $path,
            $headers
        );

        try {
            $this->logRequest('DELETE', $request, $representation);
            $response = $this->transport->send($request);
            $this->logResponse('DELETE', $response);
        } catch (BadResponseException $exception) {
            $this->logError('DELETE', $exception);
            throw $this->handleErrorResponse($exception);
        }

         return $response->getBody(true);
    }

    /**
     * Get the transport object
     * @return GuzzleClient $client
     */
    public function getTransport()
    {
        return $this->transport;
    }

    /**
     * Handle an error response exception / object
     * @param BadResponseException $exception
     * @return ErrorException $error_exception
     */
    protected function handleErrorResponse(BadResponseException $exception)
    {
        $response = Response::create($exception->getResponse());
        $errorException = new ErrorException('An error occurred on this request', 0, $exception);
        $errorException->setResponse($response);

        $contentType = $response->getHttpHeader('Content-Type');
        if (!empty($contentType)) {
            $errorClass = $this->getErrorDocumentClass($contentType);
            if (!is_null($errorClass)) {
                $errorDocument = $errorClass::createFromString($response->getBody());
                $errorException->setErrorDocument($errorDocument);
            }
        }

        return $errorException;
    }

    /**
     * Get the Error Document class from the response's content type
     * @param $contentType
     * @return string|null
     */
    protected function getErrorDocumentClass($contentType)
    {
        if (empty($this->errorResponseClass)) {
            foreach ($this->getErrorDocumentClasses() as $errorClass) {
                /* @var \DrestCommon\Error\Response\ResponseInterface $errorClass */
                if ($errorClass::getContentType() == $contentType) {
                    $this->errorResponseClass = $errorClass;
                    break;
                }
            }
        }
        return $this->errorResponseClass;
    }

    /**
     * Get all registered error document classes
     * @return array $classes
     */
    protected function getErrorDocumentClasses()
    {
        $classes = array();
        $path = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'Error' . DIRECTORY_SEPARATOR . 'Response');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            /* @var \SplFileInfo $file */
            if (!$file->getExtension() === 'php') {
                continue;
            }
            $path = $file->getRealPath();

            if (!empty($path)) {
                include_once $path;
            }
        }

        foreach (get_declared_classes() as $className) {
            $reflClass = new \ReflectionClass($className);
            if (array_key_exists('DrestCommon\\Error\\Response\\ResponseInterface', $reflClass->getInterfaces())) {
                $classes[] = $className;
            }
        }

        return $classes;
    }

    /**
     * Get the variables from a path
     * @param string $path
     * @return array $vars
     */
    protected function getVarsFromPath($path)
    {
        $vars = array();
        $urlParts = preg_split('/[?]/', $path);
        if (isset($urlParts[1])) {
            parse_str($urlParts[1], $vars);
        }
        return $vars;
    }
    /**
     * Log some parameters of the request
     * @param string $type HTTP Method 
     * @param \Guzzle\Http\Message\RequestInterface $request
     * @param \DrestCommon\Representation\AbstractRepresentation $representation
     */
    protected function logRequest($type, $request, AbstractRepresentation $representation){
        $context = array(
            'headers'   => $request->getRawHeaders(),
            'params'    => $request->getParams()->toArray(),
            'body'      => $representation->__toString()
        );
        $this->log('Request '.$type, $context);
    }
    /**
     * Log some parameters of the response
     * @param string $type
     * @param \Guzzle\Http\Message\Response $response
     */
    protected function logResponse($type, Response $response){
        $context = array(
            'headers'   => $response->getRawHeaders(),
            'params'    => $response->getParams()->toArray(),
            'body'      => $response->getBody(true)
        );
        $this->log('Response '.$type, $context);
    }


    protected function logError($type, $exception){
        $context = array(
            'message'   => $exception->getMessage(),
            'response' => $exception->getResponse()->getMessage()
        );
        $logger = $this->getLogger();
        if($logger instanceof LoggerInterface){
            $this->getLogger()->error('ERROR '.$type, $context);
        }
    }
    /**
     * Log parameters if the logger is set
     * @param string $message
     * @param array $vars
     */
    protected function log($message, array $vars){
        $logger = $this->getLogger();
        if($logger instanceof LoggerInterface){
            $this->getLogger()->debug($message, $vars);
        }
    }
    /**
     * 
     * @return LoggerInterface
     */
    public function getLogger(){
        return $this->logger;
    }
    /**
     * Set the logger used
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger){
        $this->logger = $logger;
    }
}

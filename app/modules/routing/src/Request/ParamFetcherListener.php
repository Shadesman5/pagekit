<?php

namespace Pagekit\Routing\Request;

use Pagekit\Event\EventSubscriberInterface;

class ParamFetcherListener implements EventSubscriberInterface
{
    protected \Pagekit\Routing\Request\ParamFetcherInterface $paramFetcher;

    /**
     * Constructor.
     *
     * @param ParamFetcherInterface $paramFetcher
     */
    public function __construct(?ParamFetcherInterface $paramFetcher = null)
    {
        $this->paramFetcher = $paramFetcher ?: new ParamFetcher;
    }

    /**
     * Maps the parameters to request attributes.
     *
     * @param $event
     */
    public function onController($event, $request): void
    {
        $controller = $event->getController();
        $attributes = $request->attributes->get('_request', []);
        $parameters = isset($attributes['value']) ? $attributes['value'] : false;
        $options = isset($attributes['options']) ? $attributes['options'] : [];

        // Symfony 6.4 compatibility: If no parameters from annotation, try to get from request
        if (is_array($controller)) {
            $r = new \ReflectionMethod($controller[0], $controller[1]);
            
            if ($parameters) {
                $this->paramFetcher->setRequest($request);
                $this->paramFetcher->setParameters($parameters, $options);

                foreach ($r->getParameters() as $index => $param) {
                    if (null !== $value = $this->paramFetcher->get($index)) {
                        $request->attributes->set($param->getName(), $value);
                    }
                }
            } else {
                // Fallback: Get parameters directly from request
                foreach ($r->getParameters() as $param) {
                    $name = $param->getName();
                    
                    // Try different sources
                    $value = null;
                    
                    // Try POST data (use all() to support both scalar and array values)
                    $postData = $request->request->all();
                    if (isset($postData[$name])) {
                        $value = $postData[$name];
                    }
                    // Try query string (use all() to support both scalar and array values)
                    elseif (isset($request->query->all()[$name])) {
                        $value = $request->query->all()[$name];
                    }
                    // Try JSON body
                    elseif ($request->getContent()) {
                        $data = json_decode($request->getContent(), true);
                        if (isset($data[$name])) {
                            $value = $data[$name];
                        }
                    }
                    
                    if ($value !== null) {
                        $request->attributes->set($name, $value);
                    }
                }
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function subscribe(): array
    {
        return [
            'controller' => ['onController', 110]
        ];
    }
}

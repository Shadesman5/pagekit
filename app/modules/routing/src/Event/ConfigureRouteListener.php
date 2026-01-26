<?php

declare(strict_types=1);

namespace Pagekit\Routing\Event;

use Pagekit\Event\EventSubscriberInterface;
use Pagekit\Routing\Attribute\Request;

/**
 * Reads Request attributes from controllers and configures routes.
 */
class ConfigureRouteListener implements EventSubscriberInterface
{
    /**
     * Reads the #[Request] attributes.
     */
    public function onConfigureRoute($event, $route): void
    {
        if (!$route->getControllerClass()) {
            return;
        }

        $class = $route->getControllerClass();
        $method = $route->getControllerMethod();

        // Check class-level Request attribute
        $classAttributes = $class->getAttributes(Request::class, \ReflectionAttribute::IS_INSTANCEOF);

        // Check method-level Request attribute (takes precedence)
        $methodAttributes = $method->getAttributes(Request::class, \ReflectionAttribute::IS_INSTANCEOF);

        // Use method attribute if available, otherwise class attribute
        $attributes = !empty($methodAttributes) ? $methodAttributes : $classAttributes;

        if (!empty($attributes)) {
            $request = $attributes[0]->newInstance();
            $data = $request->getData();
            $csrf = $request->getCsrf();
            
            // Only set _request if there's data or csrf is required
            if ($data || $csrf) {
                // Format expected by ParamFetcherListener and CsrfListener
                $options = $csrf ? ['csrf' => true] : [];
                $route->setDefault('_request', ['value' => $data, 'options' => $options, 'csrf' => $csrf]);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function subscribe(): array
    {
        return [
            'route.configure' => 'onConfigureRoute'
        ];
    }
}

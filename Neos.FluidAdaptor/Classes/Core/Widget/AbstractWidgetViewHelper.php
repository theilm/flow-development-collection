<?php
namespace Neos\FluidAdaptor\Core\Widget;

/*
 * This file is part of the Neos.FluidAdaptor package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\Exception\ForwardException;
use Neos\Flow\Mvc\Exception\InfiniteLoopException;
use Neos\Flow\Mvc\Exception\StopActionException;
use Neos\Flow\ObjectManagement\DependencyInjection\DependencyProxy;
use Neos\FluidAdaptor\Core\Rendering\RenderingContext;
use Neos\FluidAdaptor\Core\ViewHelper\AbstractViewHelper;
use Neos\FluidAdaptor\Core\ViewHelper\Facets\ChildNodeAccessInterface;
use TYPO3Fluid\Fluid\Core\Compiler\TemplateCompiler;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\RootNode;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\ViewHelperNode;

/**
 * @api
 */
abstract class AbstractWidgetViewHelper extends AbstractViewHelper implements ChildNodeAccessInterface
{
    /**
     * @var boolean
     */
    protected $escapeOutput = false;

    /**
     * The Controller associated to this widget.
     * This needs to be filled by the individual subclass using
     * property injection.
     *
     * @var AbstractWidgetController
     * @api
     */
    protected $controller;

    /**
     * If set to true, it is an AJAX widget.
     *
     * @var boolean
     * @api
     */
    protected $ajaxWidget = false;

    /**
     * If set to false, this widget won't create a session (only relevant for AJAX widgets).
     *
     * You then need to manually add the serialized configuration data to your links, by
     * setting "includeWidgetContext" to true in the widget link and URI ViewHelpers.
     *
     * @var boolean
     * @api
     */
    protected $storeConfigurationInSession = true;

    /**
     * @var AjaxWidgetContextHolder
     */
    private $ajaxWidgetContextHolder;

    /**
     * @var WidgetContext
     */
    private $widgetContext;

    /**
     * @param AjaxWidgetContextHolder $ajaxWidgetContextHolder
     * @return void
     */
    public function injectAjaxWidgetContextHolder(AjaxWidgetContextHolder $ajaxWidgetContextHolder)
    {
        $this->ajaxWidgetContextHolder = $ajaxWidgetContextHolder;
    }

    /**
     * @param WidgetContext $widgetContext
     * @return void
     */
    public function injectWidgetContext(WidgetContext $widgetContext)
    {
        $this->widgetContext = $widgetContext;
    }

    /**
     * Registers the widgetId viewhelper
     *
     * @return void
     */
    public function initializeArguments()
    {
        $this->registerArgument('widgetId', 'string', 'Unique identifier of the widget instance');
    }

    /**
     * Initialize the arguments of the ViewHelper, and call the render() method of the ViewHelper.
     *
     * @return string the rendered ViewHelper.
     */
    public function initializeArgumentsAndRender()
    {
        $this->validateArguments();
        $this->initialize();
        $this->initializeWidgetContext();

        return $this->callRenderMethod();
    }

    /**
     * Initialize the Widget Context, before the Render method is called.
     *
     * @return void
     * @throws \Exception
     */
    private function initializeWidgetContext()
    {
        /*
         * We reset the state of the ViewHelper by generating a new WidgetContext to handle multiple occurrences of one instance (e.g. in a ForViewHelper).
         *
         * By only calling $this->resetState() we would end in a situation where the RenderChildrenViewHelper could not find its children therefore we move the original children to the new WidgetContext.
         * We create new instances of RootNode and RenderingContext in case we got null because setViewHelperChildNodes requires its parameters to be corresponding instances.
         */
        $rootNode = $this->widgetContext->getViewHelperChildNodes() ?? new RootNode();
        $renderingContext = $this->widgetContext->getViewHelperChildNodeRenderingContext() ?? new RenderingContext();
        $this->resetState();
        $this->widgetContext->setViewHelperChildNodes($rootNode, $renderingContext);
        if ($this->ajaxWidget === true) {
            if ($this->storeConfigurationInSession === true) {
                $this->ajaxWidgetContextHolder->store($this->widgetContext);
            }
            $this->widgetContext->setAjaxWidgetConfiguration($this->getAjaxWidgetConfiguration());
        }

        $this->widgetContext->setNonAjaxWidgetConfiguration($this->getNonAjaxWidgetConfiguration());
        $this->initializeWidgetIdentifier();

        $controllerObjectName = ($this->controller instanceof DependencyProxy) ? $this->controller->_getClassName() : get_class($this->controller);
        $this->widgetContext->setControllerObjectName($controllerObjectName);
    }

    /**
     * Stores the syntax tree child nodes in the Widget Context, so they can be
     * rendered with <f:widget.renderChildren> lateron.
     *
     * @param array $childNodes The SyntaxTree Child nodes of this ViewHelper.
     * @return void
     */
    public function setChildNodes(array $childNodes)
    {
        $rootNode = new RootNode();

        foreach ($childNodes as $childNode) {
            $rootNode->addChildNode($childNode);
        }
        $this->widgetContext->setViewHelperChildNodes($rootNode, $this->renderingContext);
    }

    /**
     * Generate the configuration for this widget. Override to adjust.
     *
     * @return array
     * @api
     */
    protected function getWidgetConfiguration()
    {
        return $this->arguments;
    }

    /**
     * Generate the configuration for this widget in AJAX context.
     *
     * By default, returns getWidgetConfiguration(). Should become API later.
     *
     * @return array
     */
    protected function getAjaxWidgetConfiguration()
    {
        return $this->getWidgetConfiguration();
    }

    /**
     * Generate the configuration for this widget in non-AJAX context.
     *
     * By default, returns getWidgetConfiguration(). Should become API later.
     *
     * @return array
     */
    protected function getNonAjaxWidgetConfiguration()
    {
        return $this->getWidgetConfiguration();
    }

    /**
     * Initiate a sub request to $this->controller. Make sure to fill $this->controller
     * via Dependency Injection.
     * @return string the response content of this request.
     * @throws Exception\InvalidControllerException
     * @throws Exception\MissingControllerException
     * @throws InfiniteLoopException
     * @throws StopActionException
     * @api
     */
    protected function initiateSubRequest()
    {
        if ($this->controller instanceof DependencyProxy) {
            $this->controller->_activateDependency();
        }
        if (!($this->controller instanceof AbstractWidgetController)) {
            throw new Exception\MissingControllerException('initiateSubRequest() can not be called if there is no controller inside $this->controller. Make sure to add the @Neos\Flow\Annotations\Inject annotation in your widget class.', 1284401632);
        }

        $subRequest = $this->controllerContext->getRequest()->createSubRequest();

        $this->passArgumentsToSubRequest($subRequest);
        $subRequest->setArgument('__widgetContext', $this->widgetContext);
        $subRequest->setArgumentNamespace('--' . $this->widgetContext->getWidgetIdentifier());

        $dispatchLoopCount = 0;
        do {
            $widgetControllerObjectName = $this->widgetContext->getControllerObjectName();
            if ($subRequest->getControllerObjectName() !== '' && $subRequest->getControllerObjectName() !== $widgetControllerObjectName) {
                throw new Exception\InvalidControllerException(sprintf('You are not allowed to initiate requests to different controllers from a widget.' . chr(10) . 'widget controller: "%s", requested controller: "%s".', $widgetControllerObjectName, $subRequest->getControllerObjectName()), 1380284579);
            }
            $subRequest->setControllerObjectName($this->widgetContext->getControllerObjectName());
            try {
                $subResponse = $this->controller->processRequest($subRequest);

                // We need to make sure to not merge content up into the parent ActionResponse because that _could_ break the parent response.
                $content = $subResponse->getBody()->getContents();

                // hacky, but part of the deal. Legacy behaviour of "mergeIntoParentResponse":
                // we have to manipulate the global response to redirect for example.
                // transfer possible headers that have been set dynamically
                foreach ($subResponse->getHeaders() as $name => $values) {
                    $this->controllerContext->getResponse()->setHttpHeader($name, $values);
                }
                // if the status code is 200 we assume it's the default and will not overrule it
                if ($subResponse->getStatusCode() !== 200) {
                    $this->controllerContext->getResponse()->setStatusCode($subResponse->getStatusCode());
                }

                return $content;
            } catch (StopActionException $exception) {
                $subResponse = $exception->response;
                $parentResponse = $this->controllerContext->getResponse()->buildHttpResponse();

                // legacy behaviour of "mergeIntoParentResponse":
                // transfer possible headers that have been set dynamically
                foreach ($subResponse->getHeaders() as $name => $values) {
                    $parentResponse = $parentResponse->withHeader($name, $values);
                }
                // if the status code is 200 we assume it's the default and will not overrule it
                if ($subResponse->getStatusCode() !== 200) {
                    $parentResponse = $parentResponse->withStatus($subResponse->getStatusCode());
                }
                // if the known body size is not empty replace the body
                // we keep the contents of $subResponse as it might contain <meta http-equiv="refresh" for redirects
                if ($subResponse->getBody()->getSize() !== 0) {
                    $parentResponse = $parentResponse->withBody($subResponse->getBody());
                }

                throw StopActionException::createForResponse($parentResponse, 'Intercepted from widget view helper.');
            } catch (ForwardException $exception) {
                $subRequest = $exception->nextRequest;
                continue;
            }
        } while ($dispatchLoopCount++ < 99);
        throw new InfiniteLoopException('Could not ultimately dispatch the widget request after ' . $dispatchLoopCount . ' iterations.', 1380282310);
    }

    /**
     * Pass the arguments of the widget to the sub request.
     *
     * @param ActionRequest $subRequest
     * @return void
     */
    private function passArgumentsToSubRequest(ActionRequest $subRequest)
    {
        $arguments = $this->controllerContext->getRequest()->getPluginArguments();
        $widgetIdentifier = $this->widgetContext->getWidgetIdentifier();

        $controllerActionName = 'index';
        if (isset($arguments[$widgetIdentifier])) {
            if (isset($arguments[$widgetIdentifier]['@action'])) {
                $controllerActionName = $arguments[$widgetIdentifier]['@action'];
                unset($arguments[$widgetIdentifier]['@action']);
            }
            $subRequest->setArguments($arguments[$widgetIdentifier]);
        }
        if ($subRequest->getControllerActionName() === '') {
            $subRequest->setControllerActionName($controllerActionName);
        }
    }

    /**
     * The widget identifier is unique on the current page, and is used
     * in the URI as a namespace for the widget's arguments.
     *
     * @return string the widget identifier for this widget
     * @return void
     */
    private function initializeWidgetIdentifier()
    {
        $widgetIdentifier = ($this->hasArgument('widgetId') ? $this->arguments['widgetId'] : strtolower(str_replace('\\', '-', get_class($this))));
        $this->widgetContext->setWidgetIdentifier($widgetIdentifier);
    }

    /**
     * Resets the ViewHelper state by creating a fresh WidgetContext
     *
     * @return void
     */
    public function resetState()
    {
        if ($this->ajaxWidget) {
            $this->widgetContext = $this->objectManager->get(WidgetContext::class);
        }
    }

    /**
     * @param string $argumentsName
     * @param string $closureName
     * @param string $initializationPhpCode
     * @param ViewHelperNode $node
     * @param TemplateCompiler $compiler
     * @return string
     */
    public function compile($argumentsName, $closureName, &$initializationPhpCode, ViewHelperNode $node, TemplateCompiler $compiler)
    {
        $compiler->disable();
        return "''";
    }
}

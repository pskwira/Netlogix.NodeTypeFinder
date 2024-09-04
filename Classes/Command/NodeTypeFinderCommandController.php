<?php
declare(strict_types=1);

namespace Netlogix\NodeTypeFinder\Command;

use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Uri;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\TypeConverter\NodeConverter;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Http\ServerRequestAttributes;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\ActionResponse;
use Neos\Flow\Mvc\Controller\Arguments;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Routing\Dto\RouteParameters;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Flow\Property\PropertyMapper;
use Neos\Flow\Property\PropertyMappingConfiguration;
use Neos\Neos\Domain\Repository\SiteRepository;
use Netlogix\NodeTypeFinder\Service\NodeTypeFinderService;
use Psr\Http\Message\UriInterface;

/**
 * @Flow\Scope("singleton")
 */
class NodeTypeFinderCommandController extends CommandController
{
    /**
     * @var NodeTypeFinderService
     * @Flow\Inject
     */
    protected $nodeTypeFinderService;

    /**
     * @var PropertyMapper
     * @Flow\Inject
     */
    protected $propertyMapper;

    /**
     * @var SiteRepository
     * @Flow\Inject
     */
    protected $siteRepository;

    /**
     * List the URLs of all pages where a node of the specified type occurs.
     *
     * @param string $nodeTypeName
     * @throws \Neos\Eel\Exception
     * @throws \Neos\Flow\Http\Exception
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     * @throws \Neos\Flow\Persistence\Exception\IllegalObjectTypeException
     * @throws \Neos\Flow\Property\Exception
     * @throws \Neos\Flow\Security\Exception
     * @throws \Neos\Neos\Exception
     */
    public function listNodeTypeOccurrencesCommand(string $nodeTypeName): void
    {
        $this->output->outputTable($this->nodeTypeFinderService->findNodeTypeOccurrences(
            $nodeTypeName,
            $this->buildControllerContext()
        ), ['Occurrence on page:']);
    }

    /**
     * Resolve the context path of a node to a uri.
     */
    public function resolveContextPathToUriCommand(string $contextPath): void
    {
        $configuration = new PropertyMappingConfiguration();
        $configuration->setTypeConverterOption(NodeConverter::class, NodeConverter::INVISIBLE_CONTENT_SHOWN, true);
        $node = $this->propertyMapper->convert($contextPath, NodeInterface::class, $configuration);

        $documentNode = $this->nodeTypeFinderService->findClosestDocumentNode($node);
        if (!$documentNode) {
            $this->outputLine('No document node found for node %s', [$node->getIdentifier()]);
            $this->quit(1);
        }
        $visible = $this->nodeTypeFinderService->isNodeVisible($documentNode);
        $uri = $this->nodeTypeFinderService->buildNodeUri($documentNode, $this->buildControllerContext(), $visible);
        if ($uri === null) {
            $this->outputLine('No URI found for node %s', [$node->getIdentifier()]);
            $this->quit(1);
        }

        $this->outputLine($uri);
    }

    private function buildControllerContext(): ControllerContext
    {
        $site = $this->siteRepository->findDefault();
        if ($site->getPrimaryDomain()) {
            $requestUri = $site->getPrimaryDomain()
                ->__toString();
        } else {
            $requestUri = 'http://localhost';
        }
        $uri = new Uri($requestUri);
        $httpRequest = self::createHttpRequestFromGlobals($uri)
            ->withAttribute(
                ServerRequestAttributes::ROUTING_PARAMETERS,
                RouteParameters::createEmpty()->withParameter('requestUriHost', $uri->getHost())
            );
        $request = ActionRequest::fromHttpRequest($httpRequest);
        $uriBuilder = new UriBuilder();
        $uriBuilder->setRequest($request);

        return new ControllerContext(
            $request,
            new ActionResponse(),
            new Arguments([]),
            $uriBuilder
        );
    }

    private static function createHttpRequestFromGlobals(UriInterface $uri): ServerRequest
    {
        $_SERVER['FLOW_REWRITEURLS'] = '1';
        $fromGlobals = ServerRequest::fromGlobals();

        return new ServerRequest(
            $fromGlobals->getMethod(),
            $uri,
            $fromGlobals->getHeaders(),
            $fromGlobals->getBody(),
            $fromGlobals->getProtocolVersion(),
            array_merge(
                $fromGlobals->getServerParams(),
                // Empty SCRIPT_NAME to prevent "./flow" in Uri
                [
                    'SCRIPT_NAME' => '',
                ]
            )
        );
    }
}

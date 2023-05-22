<?php
declare(strict_types=1);

namespace Netlogix\NodeTypeFinder\Service;

use Neos\ContentRepository\Domain\Service\ContentDimensionCombinator;
use Neos\Flow\Annotations as Flow;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Neos\Service\LinkingService;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;

/**
 * @Flow\Scope("singleton")
 */
class NodeTypeFinderService
{
    /**
     * @Flow\Inject
     * @var LinkingService
     */
    protected $linkingService;

    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @Flow\Inject
     * @var ContentDimensionCombinator
     */
    protected $contentDimensionCombinator;

    /**
     * @param string $nodeTypeName
     * @param ControllerContext $controllerContext
     * @return array
     */
    public function findNodeTypeOccurrences(string $nodeTypeName, ControllerContext $controllerContext): array
    {
        $occurrences = [];

        foreach ($this->findNodeTypeOccurrencesInAllDimensions($nodeTypeName) as $node) {
            if (!$node instanceof NodeInterface) {
                continue;
            }

            $documentNode = $this->findClosestDocumentNode($node);
            if (!$documentNode) {
                continue;
            }
            $uri = $this->buildNodeUri($documentNode, $controllerContext);

            if (!array_key_exists($uri, $occurrences)) {
                $occurrences[$uri] = [
                    'url' => str_replace('./', '', $uri),
                    'label' => $documentNode->getLabel(),
                    'visible' => $documentNode->isVisible(),
                ];
            }
        }

        return array_values($occurrences);
    }

    private function findNodeTypeOccurrencesInAllDimensions(string $nodeTypeName): iterable
    {
        $dimensionCombinations = $this->contentDimensionCombinator->getAllAllowedCombinations();

        foreach ($dimensionCombinations as $dimensionCombination) {
            yield from $this->findNodeTypeOccurrencesInDimensions(
                $nodeTypeName,
                $dimensionCombination
            );
        }
    }

    private function findNodeTypeOccurrencesInDimensions(string $nodeTypeName, array $dimensionValues): iterable
    {
        $context = $this->contextFactory->create([
            'workspaceName' => 'live',
            'dimensions' => $dimensionValues,
        ]);

        yield from (new FlowQuery([$context->getCurrentSiteNode()]))
            ->find('/')
            ->find('[instanceof '.$nodeTypeName.']')
            ->get();
    }

    private function findClosestDocumentNode(NodeInterface $node): ?NodeInterface
    {
        $documentQuery = new FlowQuery([$node]);
        $documentNode = $documentQuery->closest('[instanceof Neos.Neos:Document]')->get(0);

        return $documentNode instanceof NodeInterface ? $documentNode : null;
    }

    private function buildNodeUri(NodeInterface $node, ControllerContext $controllerContext): ?string
    {
        return $this->linkingService->createNodeUri(
            $controllerContext,
            $node,
            null,
            null,
            true
        );
    }

}

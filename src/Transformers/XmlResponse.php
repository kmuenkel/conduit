<?php

namespace Conduit\Transformers;

use DOMNode;
use DOMXpath;
use DOMNodeList;
use DOMDocument;
use ArrayIterator;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;

/**
 * Class JsonTransformer
 * @package Conduit\Transformers
 */
class XmlResponse extends ResponseStruct
{
    /**
     * @var string
     */
    protected $version = '1.0';

    /**
     * @var string
     */
    protected $encoding = 'UTF-8';

    /**
     * @var DOMXpath
     */
    protected $content;

    /**
     * @param string $encoding
     * @return $this
     */
    public function setEncoding(string $encoding): XmlResponse
    {
        $this->encoding = $encoding;

        return $this;
    }

    /**
     * @param string $version
     * @return XmlResponse
     */
    public function setVersion(string $version): XmlResponse
    {
        $this->version = $version;

        return $this;
    }

    /**
     * @param ResponseInterface $response
     * @return $this
     */
    public function __invoke(ResponseInterface $response): ResponseStruct
    {
        $body = $response->getBody();
        $doc = app(DOMDocument::class, ['version' => $this->version, 'encoding' => $this->encoding]);
        $this->load($doc, $body);
        $this->content = app(DOMXpath::class, compact('doc'));

        return $this;
    }

    /**
     * @param DOMDocument $doc
     * @param string $body
     */
    protected function load(DOMDocument $doc, string $body)
    {
        $doc->loadXML($body);
    }

    /**
     * {@inheritDoc}
     */
    public function has($offset): bool
    {
        return (bool)$this->get($offset);
    }

    /**
     * {@inheritDoc}
     */
    public function unset($offset)
    {
        $nodes = $this->get($offset);
        foreach ($nodes as $node) {
            $node->parentNode->removeChild($node);
        }

        return $this;
    }

    /**
     * @param string|int $offset
     * @return DOMNodeList|DOMNode|DOMNode[]
     */
    public function get($offset)
    {
        return is_int($offset) ? $this->content->document->childNodes->item($offset) : $this->content->query($offset);
    }

    /**
     * @param int|string $offset
     * @param DOMNode $value
     * @return ResponseStruct
     */
    public function set($offset, $value)
    {
        $value = ($value instanceof DOMNodeList) ? iterator_to_array($value) : $value;

        $nodes = $this->get($offset);
        foreach ($nodes as $node) {
            $newNode = is_array($value) ? array_shift($value) : $value;
            if (!($newNode instanceof DOMNode)) {
                throw new InvalidArgumentException('Replacements must be instances of '.DOMNode::class);
            }

            $node->parentNode->replaceChild($node, $newNode);
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function count(): int
    {
        return $this->content->document->childNodes->count();
    }

    /**
     * {@inheritDoc}
     */
    public function iterator(): ArrayIterator
    {
        return app(ArrayIterator::class, ['array' => iterator_to_array($this->content->document->childNodes)]);
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return $this->content->document->saveXML();
    }
}

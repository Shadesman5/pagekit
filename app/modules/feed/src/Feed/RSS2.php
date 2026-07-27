<?php

declare(strict_types=1);

namespace Pagekit\Feed\Feed;

use Pagekit\Feed\Feed;

class RSS2 extends Feed
{
    protected string $mime = 'application/rss+xml';

    /** @var class-string<\Pagekit\Feed\ItemInterface> */
    protected string $item = \Pagekit\Feed\Item\RSS2::class;

    /**
     * {@inheritdoc}
     *
     * @return $this
     */
    public function setDate(\DateTimeInterface $date): self
    {
        $this->setElement('lastBuildDate', $date->format(\DATE_RSS));

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    protected function build(): \DOMDocument
    {
        $doc = new \DOMDocument('1.0', $this->encoding);

        $root = $doc->appendChild($doc->createElement('rss'));
        $root->setAttribute('version', '2.0');

        $channel = $root->appendChild($doc->createElement('channel'));

        foreach ($this->getElements() as $element) {
            $channel->appendChild($this->buildElement($doc, $element));
        }

        foreach ($this->items as $item) {
            $elem = $channel->appendChild($doc->createElement('item'));
            foreach ($item->getElements() as $element) {
                $elem->appendChild($this->buildElement($doc, $element));
            }
        }

        return $doc;
    }
}

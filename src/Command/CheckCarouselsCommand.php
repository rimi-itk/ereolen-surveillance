<?php

/*
 * This file is part of ereolen-surveillance.
 *
 * (c) 2019 ITK Development
 *
 * This source file is subject to the MIT license.
 */

namespace App\Command;

use App\Entity\CarouselClash;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use League\Uri\Http;
use League\Uri\Modifiers\Resolve;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CheckCarouselsCommand extends Command
{
    /** @var OutputInterface */
    private $output;

    /** @var EntityManagerInterface */
    private $entityManager;

    /** @var \Swift_Mailer */
    private $mailer;

    public function __construct(EntityManagerInterface $entityManager, \Swift_Mailer $mailer)
    {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->mailer = $mailer;
    }

    public function configure()
    {
        $this->setName('app:check-carousels')
            ->addArgument(
                'url',
                InputArgument::REQUIRED | InputArgument::IS_ARRAY,
                'The url to check'
            )
            ->addOption('name-filter', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Filter caruosel by name');
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;

        $urls = $input->getArgument('url');
        $filters = [
            'name' => $input->getOption('name-filter'),
        ];

        foreach ($urls as $url) {
            $this->checkCarousels($url, $filters);
        }
    }

    private function checkCarousels(string $url, array $filters = [])
    {
        $content = $this->getContent($url);

        $doc = new \DOMDocument();
        @$doc->loadHTML($content);

        // Remove user info from url.
        $cleanUrl = (string) Http::createFromString($url)->withUserInfo(null);

        $xpath = new \DOMXPath($doc);
        $expression = '//div[contains(concat(" ", normalize-space(@class), " "), " ding-carousel ")]';
        $carousels = $xpath->query($expression);
        foreach ($carousels as $carousel) {
            $headerElement = $this->getElementByClassName($xpath, 'carousel__header', $carousel, 'descendant::h2');
            if ($more = $this->getElementByClassName($xpath, 'carousel__more-link', $headerElement)) {
                $more->parentNode->removeChild($more);
            }
            $name = $headerElement ? trim($headerElement->textContent) : 'carousel';

            if (!empty($filters['name']) && !\in_array($name, $filters['name'], true)) {
                continue;
            }

            $this->output->writeln($name);

            $stuff = [];

            $this->loadCaruselItems($carousel, $url, $xpath);

            $expression = 'descendant::li[contains(concat(" ", normalize-space(@class), " "), " ding-carousel-item ")]';
            $items = $xpath->query($expression, $carousel);

            $this->output->writeln('#items: '.$items->length);

            foreach ($items as $index => $item) {
                $icon = $this->getElementByClassName($xpath, 'icon', $item);
                $type = preg_replace('/icon /', '', $this->getAttribute($icon, 'class'));

                if (empty($type)) {
                  // DDB CMS
                  $el = $this->getElementByClassName($xpath, 'availability', $item);
                  $type = null !== $el ? $this->getTextContent($el) : null;
                }

                $data = [
                    'language' => $this->getTextContent($this->getElementByClassName(
                        $xpath,
                        'field-name-ting-details-language',
                        $item
                    )),
                    'title' => $this->getTextContent($this->getElementByClassName(
                        $xpath,
                        'field-name-ting-title',
                        $item
                    )),
                    'author' => $this->getTextContent($this->getElementByClassName(
                        $xpath,
                        'field-name-ting-author',
                        $item
                    )),
                    'url' => $this->getAttribute($this->getElement($xpath, 'descendant::a', $item), 'href'),
                    'type' => $type,
                    'index' => $index,
                ];

                $this->output->writeln(sprintf('% 3d %s', $index, urldecode($data['url'])));

                if ($data['title'] && $data['author'] && $data['type']) {
                    $key = implode('||||', [$data['title'], $data['author'], $data['type']]);

                    if (isset($stuff[$key])) {
                        $current = $data;
                        $previous = $stuff[$key];

                        $clash = (new CarouselClash())
                            ->setCreatedAt(new \DateTime())
                            ->setUrl($cleanUrl)
                            ->setName($name)
                            ->setData([
                                'url' => $cleanUrl,
                                'data-path' => $this->getAttribute($carousel, 'data-path'),
                                'current' => $current,
                                'previous' => $previous,
                                'diff' => array_diff($current, $previous),
                            ]);

                        $this->entityManager->persist($clash);
                        $this->entityManager->flush();

                        $this->output->writeln('<error>'
                             .'Clash: '.json_encode($clash->getData(), JSON_PRETTY_PRINT)
                             .'</error>');
                    } else {
                        $stuff[$key] = $data;
                    }
                }
            }

            $this->output->writeln('');
        }
    }

    private function loadCaruselItems(\DOMElement $carousel, string $url, \DOMXPath $xpath)
    {
        $dataPath = $this->getAttribute($carousel, 'data-path');
        if (null !== $dataPath && preg_match('/^(reol_field_carousel|ting_search_carousel)/', $dataPath)) {
            $resolver = new Resolve(Http::createFromString($url));
            $offset = 0;
            $content = '';
            while ($offset > -1 && $offset < 4 * 8) {
                $uri = Http::createFromString('/'.ltrim($dataPath, '/').'/'.$offset);
                $dataUrl = (string) $resolver->process($uri);
                $this->output->writeln($dataUrl);

                $client = new Client();
                $response = $client->get($dataUrl);
                $data = json_decode((string) $response->getBody());

                $content .= $data->content;

                $offset = $data->offset ?? -1;
            }

            $doc = new \DOMDocument();
            @$doc->loadHTML('<ol>'.$content.'</ol>');

            $list = $xpath->query('descendant::ul', $carousel);
            foreach ($list as $item) {
                $item->parentNode->removeChild($item);
            }
            $carousel->appendChild($carousel->ownerDocument->importNode($doc->documentElement->firstChild->firstChild, true));

            // Remove placeholder items.
            $placeholders = $this->getElementsByClassName($xpath, 'placeholder', $carousel);
            foreach ($placeholders as $placeholder) {
                $placeholder->parentNode->removeChild($placeholder);
            }
        }
    }

    private function getElement($xpath, $expression, $context = null)
    {
        $result = $xpath->query($expression, $context);

        return 0 < $result->length ? $result->item(0) : null;
    }

    /**
     * @param \DOMXPath     $xpath
     * @param string        $className
     * @param null|\DOMNode $context
     *
     * @return \DOMNodeList
     */
    private function getElementsByClassName(\DOMXPath $xpath, string $className, \DOMNode $context = null, string $expression = 'descendant::*')
    {
        return $xpath->query($expression.'[contains(concat(" ", normalize-space(@class), " "), " '.$className.' ")]', $context);
    }

    /**
     * @param \DOMXPath     $xpath
     * @param string        $className
     * @param null|\DOMNode $context
     *
     * @return null|\DOMNode
     */
    private function getElementByClassName(\DOMXPath $xpath, string $className, \DOMNode $context = null, string $expression = 'descendant::*')
    {
        $result = $this->getElementsByClassName($xpath, $className, $context, $expression);

        return 0 < $result->length ? $result->item(0) : null;
    }

    /**
     * @param \DOMElement $element
     * @param string      $attributeName
     * @param null        $default
     *
     * @return null|string
     */
    private function getAttribute(?\DOMElement $element, string $attributeName, $default = null)
    {
        return null !== $element ? $element->getAttribute($attributeName) : null;
    }

    private function getTextContent($element)
    {
        return null !== $element ? $element->textContent : null;
    }

    private function getContent(string $url): string
    {
        if (0 === strpos($url, 'file://')) {
            return file_get_contents($url);
        }
        $client = new Client();
        $response = $client->get($url);

        return (string) $response->getBody();
    }
}

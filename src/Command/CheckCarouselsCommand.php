<?php

namespace App\Command;

use App\Entity\CarouselClash;
use App\Repository\CarouselClashRepository;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CheckCarouselsCommand extends Command
{
    /** @var  OutputInterface */
    private $output;

    /** @var EntityManagerInterface  */
    private $entityManager;

    /** @var \Swift_Mailer  */
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
          ->addArgument('url',
            InputArgument::REQUIRED | InputArgument::IS_ARRAY,
            'The url to check');
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;

        $urls = $input->getArgument('url');

        foreach ($urls as $url) {
            $this->checkCarousels($url);
        }
    }

    private function checkCarousels(string $url)
    {
        $content = $this->getContent($url);

        $doc = new \DOMDocument();
        @$doc->loadHTML($content);

        $xpath = new \DOMXPath($doc);
        $expression = '//div[contains(concat(" ", normalize-space(@class), " "), " ding-carousel ")]';
        $carousels = $xpath->query($expression);
        foreach ($carousels as $carousel) {
            $header = preg_replace('/\s+/', ' ', trim($xpath->query('descendant::h2', $carousel)->item(0)->textContent));
            $expression = 'descendant::li[contains(concat(" ", normalize-space(@class), " "), " ding-carousel-item ")]';
            $items = $xpath->query($expression, $carousel);

            $stuff = [];

            $this->output->writeln($header);
            $this->output->writeln('#items: '. $items->length);
            foreach ($items as $item) {
                $icon = $this->getElementByClassName($xpath, 'icon', $item);
                $type = preg_replace('/icon /', '', $this->getAttribute($icon, 'class'));

                $data = [
                    'language' => $this->getTextContent($this->getElementByClassName($xpath, 'field-name-ting-details-language', $item)),
                    'title' => $this->getTextContent($this->getElementByClassName($xpath, 'field-name-ting-title', $item)),
                    'author' => $this->getTextContent($this->getElementByClassName($xpath, 'field-name-ting-author', $item)),
                    'url' => $this->getAttribute($this->getElement($xpath, 'descendant::a', $item), 'href'),
                    'type' => $type,
                ];

                if ($data['title'] && $data['author'] && $data['type']) {
                    $key = implode('||||', [$data['title'], $data['author'], $data['type']]);
                    if (isset($stuff[$key])) {
                        $current = $data;
                        $previous = $stuff[$key];

                        $clash = (new CarouselClash())
                            ->setCreatedAt(new \DateTime())
                            ->setUrl($url)
                            ->setName($header)
                            ->setData([
                                'url' => $url,
                                'carousel' => $header,
                                'current' => $current,
                                'previous' => $previous,
                                'diff' => array_diff($current, $previous),
                            ]);

                        $this->entityManager->persist($clash);
                        $this->entityManager->flush();

                        $this->output->writeln('<error>'
                            .'Clash: '.\json_encode($clash->getData(), JSON_PRETTY_PRINT)
                            .'</error>');
                    }

                    $stuff[$key] = $data;
                }
            }
            $this->output->writeln('');
        }
    }

    private function getElement($xpath, $expression, $context = null)
    {
        $result = $xpath->query($expression, $context);

        return 1 === $result->length ? $result->item(0) : null;
    }

    private function getElementByClassName($xpath, $className, $context = null)
    {
        $result = $xpath->query('descendant::*[contains(concat(" ", normalize-space(@class), " "), " '.$className.' ")]', $context);

        return 1 === $result->length ? $result->item(0) : null;
    }

    private function getAttribute($element, $attributeName, $default = null)
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
        } else {
            $client = new Client();
            $response = $client->get($url);
            return (string)$response->getBody();
        }
    }
}

<?php

namespace RainLab\Sitemap\console;

use Url;
use Config;
use Cms\Classes\Theme;
use Illuminate\Console\Command;
use Rainlab\Sitemap\Models\Definition;


class GenerateSitemap extends Command
{

    /**
     * @var string The console command name.
     */
    protected $name = 'sitemap:generate';

    /**
     * @var string The console command description.
     */
    protected $description = 'Generate sitemap for site';

    private string $rootUrl;

    public function __construct()
    {
        parent::__construct();

        $this->rootUrl = rtrim(Config::get('app.url'), '/') ;
    }

    /**
     * @param string $uri
     * @return string
     */
    private function resolveUrl(string $uri): string
    {
        return strpos($uri, $this->rootUrl) === false ? $this->rootUrl. '/' . ltrim($uri, '/') : $uri;
    }


    protected function addItem(\XMLWriter $writer, string $uri, $mtime, string $changefreq, float $priority)
    {
        if ($mtime instanceof \DateTime) {
            $mtime = $mtime->getTimestamp();
        }

        if (is_string($mtime)) {
           $mtime = null;
        }

        $mtime = $mtime ? date('c', $mtime) : date('c');

        $writer->startElement('url');

        $writer->startElement('loc');
        $writer->text($this->resolveUrl($uri));
        $writer->endElement();

        $writer->startElement('lastmod');
        $writer->text($mtime);
        $writer->endElement();

        $writer->startElement('changefreq');
        $writer->text($changefreq);
        $writer->endElement();

        $writer->startElement('priority');
        $writer->text($priority);
        $writer->endElement();

        $writer->endElement();
    }

    /**
     * Execute the console command.
     * @throws \ApplicationException
     * @throws \DOMException
     */
    public function handle()
    {
        $theme = Theme::getActiveTheme()->getDirName();

        $definition = Definition::where('theme', $theme)->first();
        if (!$definition) {
            $this->info('Sitemap: definition is empty.');
            return;
        }

        if (!$items = $definition->items) {
            return;
        }

        unset($definition);

        $theme = Theme::load($theme);
        $count = 0;

        if (file_exists(temp_path('sitemap.tmp'))) {
            unlink(temp_path('sitemap.tmp'));
        }

        $stream = fopen(temp_path('sitemap.tmp'), 'c');

        $xmlWriter = new \XMLWriter();
        $xmlWriter->openMemory();
        $xmlWriter->startDocument('1.0', 'UTF-8');

        $xmlWriter->startElement('urlset');

        foreach ($items as $item) {
            if ($item->type == 'url') {
                $count++;
                $this->addItem($xmlWriter, $item->url, time(), $item->changefreq, $item->priority);
                fwrite($stream, $xmlWriter->flush());
                unset($item);
            } else {

                $apiResult = \Event::fire('pages.menuitem.resolveItem', [$item->type, $item, '/', $theme]);

                if (!is_array($apiResult)) {
                    unset($apiResult);
                    continue;
                }

                foreach ($apiResult as $itemInfo) {
                    /*
                     * Callable
                     */
                    if (is_callable($itemInfo)) {
                        $is = call_user_func($itemInfo);

                        if (false === $is instanceof \Generator) {
                            continue;
                        }

                        foreach ($is as $i) {
                            if (isset($i['url'])) {
                                $count++;
                                $this->addItem($xmlWriter, $i['url'], $i['mtime'], $item->changefreq, $item->priority);
                                fwrite($stream, $xmlWriter->flush());
                            }
                            unset($i);
                        }
                    }

                    if (!is_array($itemInfo)) {
                        unset($itemInfo);
                        continue;
                    }

                    /*
                     * Single item
                     */
                    if (isset($itemInfo['url'])) {
                        $count++;
                        $this->addItem($xmlWriter, $itemInfo['url'], $itemInfo['mtime'], $item->changefreq, $item->priority);
                        fwrite($stream, $xmlWriter->flush());
                    }

                    /*
                     * Multiple items
                     */
                    if (isset($itemInfo['items'])) {

                        foreach ($itemInfo['items'] as $it) {
                            if (isset($it['url'])) {
                                $count++;
                                $this->addItem($xmlWriter, $it['url'], $it['mtime'], $item->changefreq, $item->priority);
                                fwrite($stream, $xmlWriter->flush());
                            }
                        }
                    }

                    unset($itemInfo);
                }
            }
        }

        $xmlWriter->endElement();

        $xmlWriter->endDocument();

        if ($buffer = $xmlWriter->flush()) {
            fwrite($stream, trim($buffer));
            unset($buffer);
        }

        if (is_resource($stream)) {
            fclose($stream);
        }

        unset($items);

        $this->info(sprintf('Total urls: %s', $count));

        if ($count > Definition::MAX_URLS) {
            $this->split();
        } else {
            copy(temp_path('sitemap.tmp'), public_path('sitemap.xml'));
        }

        if (file_exists(temp_path('sitemap.tmp'))) {
            unlink(temp_path('sitemap.tmp'));
        }

        $this->info('Sitemap(s) successfully generated');
    }

    /**
     * @return void
     */
    private function split()
    {
        $reader = new \XMLReader();
        $writer = new \XMLWriter();

        $itemCount = 0;
        $fileIndex = 1;

        $reader->open(temp_path('sitemap.tmp'));

        function startNewFile($fileIndex, $writer) {
            $fileName = sprintf('sitemap-%d.xml', $fileIndex);
            $writer->openURI(public_path($fileName));
            $writer->startDocument('1.0', 'UTF-8');
            $writer->startElement('urlset');

            $writer->startAttribute('xmlns');
            $writer->text('http://www.sitemaps.org/schemas/sitemap/0.9');
            $writer->endAttribute();

            $writer->startAttribute('xmlns:xhtml');
            $writer->text('https://www.w3.org/1999/xhtml');
            $writer->endAttribute();

            $writer->startAttribute('xmlns:xsi');
            $writer->text('http://www.w3.org/2001/XMLSchema-instance');
            $writer->endAttribute();

            $writer->startAttribute('xsi:schemaLocation');
            $writer->text('http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd');
            $writer->endAttribute();
        }

        startNewFile($fileIndex, $writer);

        while ($reader->read()) {
            if ($reader->nodeType == \XMLReader::ELEMENT && $reader->localName == 'url') {
                // Счетчик элементов в текущем файле
                if ($itemCount >= Definition::MAX_URLS) {
                    // Закрыть текущий элемент и документ
                    $writer->endElement();
                    $writer->endDocument();

                    // Сброс счетчиков и индексации файлов
                    $itemCount = 0;
                    $fileIndex++;

                    // Начать новый файл
                    startNewFile($fileIndex, $writer);
                }

                // Копирование элемента <url> целиком
                $writer->startElement('url');

                if (!$reader->isEmptyElement) {
                    $reader->read();
                    while ($reader->nodeType != \XMLReader::END_ELEMENT) {
                        if ($reader->nodeType == \XMLReader::ELEMENT) {
                            $writer->startElement($reader->name);
                            if (!$reader->isEmptyElement) {
                                $reader->read();
                                if ($reader->nodeType == \XMLReader::TEXT) {
                                    $writer->text($reader->value);
                                }
                                $reader->read();
                            }
                            $writer->endElement();
                        }
                        $reader->read();
                    }
                }
                $writer->endElement();
                $itemCount++;
            }
        }

        $writer->endElement(); // urlset
        $writer->endDocument();

        $writer->flush();
        $reader->close();

        $this->generateIndexSitemap($fileIndex);
    }

    /**
     * @param int $count
     * @return void
     */
    protected function generateIndexSitemap(int $count): void
    {
        // Инициализация XmlWriter и начало документа
        $writer = new \XMLWriter();
        $writer->openURI(public_path('sitemap.xml'));
        $writer->startDocument('1.0', 'UTF-8');

        // Начало элемента sitemapindex
        $writer->startElement('sitemapindex');
        $writer->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        for ($i = 0; $i < $count; $i++) {

            $loc = sprintf('%s/sitemap-%d.xml', rtrim($this->rootUrl, '/'), ($i + 1));

            $writer->startElement('sitemap');
            $writer->writeElement('loc', $loc);
            $writer->endElement();
        }

        $writer->endElement();

        $writer->endDocument();

        $writer->flush();
    }
}
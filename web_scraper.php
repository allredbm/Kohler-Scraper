<?php

class WebScraper
{
	/**
	* Base category URLs for searching the full catalog
	*
	* @var array
	*/
	protected $baseCategoryUrls = array(
		'https://www.us.kohler.com/us/Bathroom/category/429204.htm',
		'https://www.us.kohler.com/us/Kitchen/category/432288.htm'
	);

	/**
	* Cache for urls where the DOM was unable to be retrieved
	*
	*@var array
	*/
	protected $domErrors = array();

	/**
	* Cache for model numbers that have already been added to the csv data
	*
	* @var array
	*/
	protected $modelNumbers = array();

	/**
	* Get product details from Kohler website as a csv
	*
	* @return void
	*/
	public function getProductDetailsCsv()
	{
		$baseUrls = $this->baseCategoryUrls;
		$categoryUrls = array();

		foreach ($baseUrls as $baseUrl) {
			$baseDom = $this->getDOM($baseUrl);
			$categoryElements = $this->fetchElementsbyClass($baseDom, 'a', 'section__category-header-link');

			foreach($categoryElements as $element) {
				array_push($categoryUrls, "https://www.us.kohler.com" . $element->getAttribute('href'));
			}
		}

		$this->getProductDetailsCsvFromCategory($categoryUrls);
	}

	/**
	* Get product details from an array of category pages and export as a csv
	*
	* @param array $categoryUrls
	*
	* @return void
	*/
	public function getProductDetailsCsvFromCategory(array $categoryUrls)
	{
		$productUrls = array();

		foreach ($categoryUrls as $categoryUrl) {
			$categoryDom = $this->getDOM($categoryUrl);
			$productElements = $this->fetchElementsbyClass($categoryDom, 'a', 'add-to-compare-link');
			
			foreach($productElements as $element) {
				array_push($productUrls, "https://www.us.kohler.com" . $element->getAttribute('href'));
			}
		}

		$this->getProductDetailsCsvFromProducts($productUrls);
	}

	/**
	* Get product details from an array of web pages and export as a csv
	*
	* @param array $urls
	*
	* @return void
	*/
	public function getProductDetailsCsvFromProducts(array $urls)
	{
		$data = array();

		foreach ($urls as $url) {
			$dom = $this->getDOM($url);

			if ($dom === FALSE || $dom->saveHTML() === FALSE) {
				array_push($this->domErrors, $url);
				continue;
			}

			$data = array_merge($data, $this->scrapeItemInfo($dom, $url));
		}

		$this->generateCsv($data);
	}

	/**
	* Resets model numbers cache for a new csv document
	*
	* @return void
	*/
	public function reset()
	{
		$this->modelNumbers = array();
	}

	/**
	* Fetch HTML contents for given URL
	* 
	* @param string $url
	* 
	* @return DOMDocument|bool
	*/
	protected function getDOM($url)
	{
		$opts = array('http'=>array('header' => "User-Agent:MyAgent/1.0\r\n"));
		$context = stream_context_create($opts);
		$contents = file_get_contents($url, false, $context);

		if (empty($contents)) {
			return FALSE;
		}

		$dom = new DOMDocument();
		$dom->loadHTML($contents);

		return $dom;
	}

	/**
	* Scrape Item info from HTML DOC
	* 
	* @param DOMDocument $dom
	* @param string $url
	* 
	* @return array
	*/
	protected function scrapeItemInfo(DOMDocument $dom, $url)
	{
		$data = array();
		$models = $this->fetchModelInfo($dom);

		$nameElement = $this->fetchElementsByClass($dom, 'h1', 'product-detail__name');
		$descriptionElement = $this->fetchElementsByClass($dom, 'p', 'product-detail__features-description');
		$featuresElement = $this->fetchElementsByClass($dom, 'div', 'product-detail__features-list');
		$categories = $this->fetchCategories($dom);

		foreach($models as $model) {
			if (in_array($model->modelNumber, $this->modelNumbers)) {
				continue;
			}

			$data[] = array(
				trim($nameElement[0]->nodeValue),
				$categories[0],
				isset($categories[1]) ? $categories[1] : NULL,
				isset($categories[2]) ? $categories[2] : NULL,
				$dom->saveHTML($descriptionElement[0]),
				$model->modelNumber,
				$model->listPrice,
				$model->imageLink,
				$this->fetchDocumentLinks($dom),
				$dom->saveHTML($featuresElement[0]),
				$url
			);

			array_push($this->modelNumbers, $model->modelNumber);
		}

		return $data;
	}

	/**
	* Fetches model numbers and images for each number from DOM
	*
	* @param DOMDocument $dom
	*
	* @return \stdClass[]
	*/
	protected function fetchModelInfo(DOMDocument $dom)
	{
		$colorsElement = $this->fetchElementsByClass($dom, 'div', 'product-detail__colors');
		$colorChoices = $colorsElement[0]->getElementsByTagName('div');
		$models = array();

		foreach($colorChoices as $colorElement) {
			$data = explode(",", $colorElement->getAttribute('data-getdata'));
			$model = (object)array(
				'modelNumber' => substr($data[0], 2, (strlen($data[0]) - 3)),
				'listPrice' => number_format((float) substr($data[1], 1, (strlen($data[1]) - 2)), 2),
				'imageLink' => substr($data[10], 1, (strlen($data[10]) - 2)),
			);

			array_push($models, $model);
		}

		return $models;
	}

	/**
	* Fetches element from DOM by classname and tag
	*
	* @param DOMDocument $dom
	* @param string $tag
	* @param string $className
	* 
	* @return DOMElement[]
	*/
	protected function fetchElementsByClass(DOMDocument $dom, $tag, $className)
	{
		$childNodeList = $dom->getElementsByTagName($tag);
		$elements = array();

		for ($i = 0; $i < $childNodeList->length; $i++) {
			$temp = $childNodeList->item($i);
			if (stripos($temp->getAttribute('class'), $className) !== false) {
				array_push($elements, $temp);
			}
		}

		return $elements;
	}

	/**
	* Fetches document links from category breadcrumb element in the DOM
	*
	* @param DOMElement $documentsElement
	*
	* @return array
	*/
	protected function fetchCategories(DOMDocument $dom)
	{
		$categoryElement = $dom->getElementById('breadcrumb-navigation');
		$links = $categoryElement->getElementsByTagName('a');
		$categories = array();

		foreach ($links as $link) {
			array_push($categories, $link->getAttribute('title'));
		}

		return $categories;
	}

	/**
	* Fetches document links from DOM documents element
	*
	* @param DOMElement $dom
	*
	* @return string
	*/
	protected function fetchDocumentLinks(DOMDocument $dom)
	{
		$documentLinks = array();
		$documentsElement = $dom->getElementById('product-detail__parts-and-resources');
		$links = $documentsElement->getElementsByTagName('a');

		foreach ($links as $link) {
			$linkOnClick = $link->getAttribute('onclick');

			if (!empty($linkOnClick)) {
				$documentLinks[] = "https://www.us.kohler.com" . substr($linkOnClick, 13, (strlen($linkOnClick) - 15));
			}
		}

		return implode(", ", $documentLinks);
	}

	/**
	* Generate CSV document from data
	* 
	* @param array $data
	*
	* @return void
	*/
	protected function generateCsv(array $data)
	{
		$timestamp = date('YmdHi');

		header("Content-type: text/csv");
		header("Content-Disposition: attachment; filename=kohler-" . $timestamp . ".csv");
		header('pragma: no-cache');
		header('Expires: 0');

		$file = fopen('kohler-' . $timestamp . '.csv', 'w');

		fputcsv($file, array('name', 'category1', 'category2', 'category3', 'description', 'model number', 'list price', 'image link', 'documents', 'features', 'url'));

		foreach($data as $row) {
			fputcsv($file, $row);
		}

		fclose($file);
	}
}

$scraper = new WebScraper();

$scraper->getProductDetailsCsv();
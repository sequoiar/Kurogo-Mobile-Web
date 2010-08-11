<?php

require_once "rss_services.php";
require_once "DiskCache.inc";

define('IMAGE_CACHE_EXTENSION', '/api/newsimages');
define('IMAGE_THUMBNAIL_SIZE', 76);
define('IMAGE_MAX_WIDTH', 600);
define('IMAGE_MAX_HEIGHT', 800);

class GazetteRSS extends RSS {

  private static $diskCache;
  private static $searchCache;
  private static $imageWriter;

  // when we resize images, store width/height in
  // a state variable
  private static $lastWidth;
  private static $lastHeight;

  // TODO: move this somewhere else instead of keeping in code
  private static $channels = array(
    array('title' => 'All News', 
          'url' => 'http://feeds.feedburner.com/HarvardGazetteOnline'),
    array('title' => 'Campus & Community',
          'url' => 'http://feeds.feedburner.com/HarvardGazetteOnlineCampusCommunity'),
    array('title' => 'Arts & Culture',
          'url' => 'http://feeds.feedburner.com/HarvardGazetteOnlineArtsCulture'),
    array('title' => 'Science & Health',
          'url' => 'http://feeds.feedburner.com/HarvardGazetteOnlineScienceHealth'),
    array('title' => 'National & World Affairs',
          'url' => 'http://feeds.feedburner.com/HarvardGazetteOnlineNationalWorldAffairs'),
    array('title' => 'Athletics',
          'url' => 'http://feeds.feedburner.com/HarvardGazetteOnlineAthletics'),
    //array('title' => 'Multimedia',
    //      'url' => 'http://feeds.feedburner.com/HarvardGazetteOnlineMultimedia'),
    );
  
  public static function init() {
    // news articles get updated continuously, so make the timeout short
    self::$diskCache = new DiskCache(CACHE_DIR . '/GAZETTE', 300, TRUE);
    self::$diskCache->setSuffix('.xml');
    self::$diskCache->preserveFormat();

    // allow cached search results to stick around longer
    self::$searchCache = new DiskCache(CACHE_DIR . '/GAZETTE_SEARCH', 3600, TRUE);
    self::$searchCache->setSuffix('.xml');
    self::$searchCache->preserveFormat();

    self::$imageWriter = new DiskCache(WEBROOT . IMAGE_CACHE_EXTENSION, PHP_INT_MAX, TRUE);
  }

  public static function getChannels() {
    $result = array();
    foreach (self::$channels as $channel) {
      $result[] = $channel['title'];
    }
    return $result;
  }

  public static function getSearchFirstId($searchTerms) {
      $dom = self::getSearchXML($searchTerms);
      return self::getFirstId($dom);
  }

  public static function getSearchLastId($searchTerms) {
      $dom = self::getSearchXML($searchTerms);
      return self::getLastId($dom);
  }

  public static function searchArticlesArray($searchTerms, $lastStoryId=NULL, $direction="forward") {
    $xml_text = self::searchArticles($searchTerms, $lastStoryId, $direction);
    $doc = new DOMDocument();
    $doc->loadXML($xml_text);
    return self::xml2Array($doc);
  }

  public static function searchArticles($searchTerms, $lastStoryId=NULL, $direction="forward") {
    $dom_document = self::getSearchXML($searchTerms);
    return self::loadArticlesFromCache($dom_document, $lastStoryId, $direction);
  }

  private static function getSearchXML($searchTerms) {
    // we will just store filenames by search terms
    if (!self::$searchCache->isFresh($searchTerms)) {
      $query = http_build_query(array('s' => $searchTerms, 'feed' => 'rss2'));
      $url = NEWS_SEARCH_URL . '?' . $query;
      $contents = file_get_contents($url);
      self::$searchCache->write($contents, $searchTerms);
    }

    $cacheFile = self::$searchCache->getFullPath($searchTerms);

    $doc = new DOMDocument();
    $doc->load($cacheFile);
    $items = $doc->getElementsByTagName("item");
    return $doc;
  }

  public static function getArticlesFirstId($channel) {
      $dom = self::getChannelXML($channel);
      return self::getFirstId($dom);
  }

  public static function getArticlesLastId($channel) {
      $dom = self::getChannelXML($channel);
      return self::getLastId($dom);
  }

  public static function getMoreArticlesArray($channel=0, $lastStoryId=NULL, $direction="forward") {
    $xml_text = self::getMoreArticles($channel, $lastStoryId, $direction);
    $doc = new DOMDocument();
    $doc->loadXML($xml_text);
    return self::xml2Array($doc);
  }

  public static function getMoreArticles($channel=0, $lastStoryId=NULL, $direction="forward") {
    $dom_document = self::getChannelXML($channel);
    return self::loadArticlesFromCache($dom_document, $lastStoryId, $direction);
  }

  private static function getChannelXML($channel) {
    if ($channel < count(self::$channels)) {
        $channelInfo = self::$channels[$channel];
        $channelUrl = $channelInfo['url'] . '?format=xml';

        $filename = self::cacheName($channelInfo['url']);
        if (!self::$diskCache->isFresh($filename)) {
            $contents = file_get_contents($channelUrl);
            self::$diskCache->write($contents, $filename);
        }

        $cacheFile = self::$diskCache->getFullPath($filename);

        $doc = new DOMDocument();
        $doc->load($cacheFile);
        return $doc;
    } else {
        throw new Exception("$channel channel number is illegal");
    }
  }

  private static function loadArticlesFromCache($dom, $lastStoryId=NULL, $direction="forward") {

    $newdoc = new DOMDocument($dom->xmlVersion, $dom->encoding);
    $rssRoot = $newdoc->importNode($dom->documentElement);
    $newdoc->appendChild($rssRoot);

    $channelRoot = $newdoc->createElement('channel');
    $rssRoot->appendChild($channelRoot);

    $numItems = $dom->getElementsByTagName('item')->length;
    if ($lastStoryId === NULL) {
      // provide a flag to the native app so it knows how many stories
      // are in this feed, since we only return up to 10
      self::appendDOMAttribute($newdoc, $channelRoot, 'items', $numItems);
    }

    $count = 0;
    $itemNodes = $dom->getElementsByTagName('item');
    
    for($index = 0; $index < $numItems; $index++) {
      if($direction == "forward") {
          $item = $itemNodes->item($index);
      } else {
          $item = $itemNodes->item($numItems-1-$index);
      }

      if ($count >= 10) {
        break;
      }

      $item = $newdoc->importNode($item, TRUE);
      if ($lastStoryId !== NULL) {
        $storyId = $item->getElementsByTagName('WPID')->item(0)->nodeValue;
	  if ($storyId == $lastStoryId) {
          $lastStoryId = NULL;
        }

      } else {
        // download and resize thumbnail image
        $thumb = $item->getElementsByTagName('image')->item(0);
        $thumbUrl = $thumb->getElementsByTagName('url')->item(0);
        $newThumbUrlString = self::imageUrl(self::cacheImage($thumbUrl->nodeValue, NULL)); // IMAGE_THUMBNAIL_SIZE));

        // replace url in rss feed
        if ($newThumbUrlString) {
          //$newThumbUrl = $newdoc->createElement('url', $newThumbUrlString);
          //$thumb->replaceChild($newThumbUrl, $thumbUrl);
        } else { // image creation bailed (perhaps from a 1px image)
          $thumb->removeChild($thumbUrl);
        }

        // since we altered the width and height, what they have there is no longer applicable
        if ($widthTag = $thumb->getElementsByTagName('width')->item(0))
          $thumb->removeChild($widthTag);
        if ($heightTag = $thumb->getElementsByTagName('height')->item(0))
          $thumb->removeChild($heightTag);

        // remove images from main <content> tag

        $contentNode = $item->getElementsByTagName('encoded')->item(0);
        $content = $contentNode->nodeValue;
        $contentHTML = new DOMDocument();
        // make sure the parser picks up encoded characters
        $contentHTML->loadHTML('<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"/></head>' . $content);

        foreach ($contentHTML->getElementsByTagName('img') as $imgTag) {
          // skip 1px tracking images
          if ($imgTag->getAttribute('width') == '1') {
            $imgTag->parentNode->removeChild($imgTag);
            continue;
          }

          // 300x400 max for inline images
          $src = $imgTag->getAttribute('src');
          $cachedImageFile = self::cacheImage($src, IMAGE_MAX_WIDTH, IMAGE_MAX_HEIGHT);
          if ($cachedImageFile) {
            $newSrcUrlString = self::imageUrl($cachedImageFile);
            $otherImage = $newdoc->createElement('image');

            $fullUrl = $contentHTML->createElement('img');

            self::appendDOMAttribute($contentHTML, $fullUrl, 'src', $newSrcUrlString);
            self::appendDOMAttribute($contentHTML, $fullUrl, 'width', intval(self::$lastWidth / 2));
            self::appendDOMAttribute($contentHTML, $fullUrl, 'height', intval(self::$lastHeight / 2));

            $imgTag->parentNode->replaceChild($fullUrl, $imgTag);

          } else {
            $imgTag->parentNode->removeChild($imgTag);
          }

        } // foreach

        $cdata = $newdoc->createCDATASection($contentHTML->saveHTML());
        $contentNode->replaceChild($cdata, $contentNode->firstChild);

        $channelRoot->appendChild($item);
        $count++;
      } // else
    } // foeach

    $result = $newdoc->saveXML();

    return $result;
  }

  private static function cacheName($url) {
    return end(explode('/', $url));
  }

  /** image caching and manipulation **/

  // returns filename of the new image created
  private static function cacheImage($imgUrl, $newWidth=NULL, $newHeight=NULL) {

    $imageName = self::imageName($imgUrl, $newWidth, $newHeight);
    if (self::$imageWriter->isFresh($imageName)) {
      if (self::$imageWriter->isEmpty($imageName))
        return FALSE;
      list(self::$lastWidth, self::$lastHeight) = self::$imageWriter->getImageSize($imageName);
      return $imageName;
    } else {
      $imageStr = file_get_contents($imgUrl);
      $bytes = strlen($imageStr);

      // if the image is too large, php will run out of memory
      // i haven't found the threshold so we will set a limit that
      // includes most images from the gazette feed.
      if ($bytes > 500000) {
        return FALSE;
      }

      // do this temporarily to keep track of how long it takes us
      // to create resized images
      error_log("$imgUrl is $bytes bytes", 0);

      if ($imageStr) {
         $image = imagecreatefromstring($imageStr);
         if ($image) {
   
           if ($newWidth === NULL && $newHeight === NULL) {
             // we don't know the image size so we just return it as unknown
             if (self::$imageWriter->writeImage($image, $imageName)) {
               // save state
               self::$lastWidth = $newWidth;
               self::$lastHeight = $newHeight;

               return $imageName;
             } else {
               return FALSE;
             }
           }
     
           $oldWidth = imagesx($image);
           $oldHeight = imagesy($image);

           // don't waste time resizing 1 pixel images
           // we need a signal so we know it's invalid the next time we
           // try to access this image -- write a blank file
           if ($oldWidth <= 1 && $oldHeight <= 1) {
             $path = self::$imageWriter->getFullPath($imageName);
             touch($path);
             return FALSE;
           }

           $oldOriginX = 0;
           $oldOriginY = 0;
     
           // if images are smaller than our max dimensions,
           // make sure they don't increase
           if ($newWidth > $oldWidth) $newWidth = $oldWidth;
           if ($newHeight > $oldHeight) $newHeight = $oldHeight;

           // if both newWidth and newHeight are specified,
           // decide whether we need to truncate in one dimension
           if ($newWidth !== NULL && $newHeight !== NULL) {
             $xScale = $newWidth / $oldWidth;
             $yScale = $newHeight / $oldHeight;
     
             // we might not get round numbers above, so use percent difference
             if (abs($xScale / $yScale - 1) > 0.05) {
               if ($yScale < $xScale) { // truncate height from center
                 $oldHeightIfSameRatio = $newHeight * $oldWidth / $newWidth;
                 $oldOriginY = ($oldHeight - $oldHeightIfSameRatio) / 2;
                 $oldHeight = $oldHeightIfSameRatio;
               } else { // truncate width from center
                 $oldWidthIfSameRatio = $newWidth * $oldHeight / $newHeight;
                 $oldOriginX = ($oldWidth - $oldWidthIfSameRatio) / 2;
                 $oldWidth = $oldWidthIfSameRatio;
               }
             }
           }

           // if only one of maxWidth or maxHeight is specified,
           // populate the other based on original image ratio
           elseif ($newWidth !== NULL && $newHeight === NULL) {     
             $newHeight = $oldHeight * $newWidth / $oldWidth;
           }

           elseif ($newWidth === NULL && $newHeight !== NULL) {
             $newWidth = $oldWidth * $newHeight / $oldHeight;
           }

           // don't resize the image if the dimensions haven't changed
           if ($oldWidth != $newWidth || $oldHeight != $newHeight) {
             $newImage = imagecreatetruecolor($newWidth, $newHeight);
             imagecopyresized($newImage, $image, 0, 0, $oldOriginX, $oldOriginY, $newWidth, $newHeight, $oldWidth, $oldHeight);
           } else {
             $newImage = $image;
           }

           if (self::$imageWriter->writeImage($newImage, $imageName)) {
             // save state
             self::$lastWidth = $newWidth;
             self::$lastHeight = $newHeight;

             return $imageName;
           }

        } // if $image
      } // if $imageStr
    } // else
  }

  private static function imageName($imgUrl, $width=NULL, $height=NULL) {
    $extension = substr($imgUrl, -4);
    $hash = crc32($imgUrl);

    return sprintf("%u", $hash) 
      . ($width === NULL ? '' : '_' . $width)
      . ($height === NULL ? '' : 'x' . $height)
      . $extension;
  }

  private static function imageUrl($filename) {
    if (!$filename) return FALSE;

    $port = $_SERVER['SERVER_PORT'] == 80 
      ? '' 
      : ':' . $_SERVER['SERVER_PORT'];

    return 'http://' . $_SERVER['SERVER_NAME'] . $port
      . IMAGE_CACHE_EXTENSION . '/' . $filename;
  }

  private static function appendDOMAttribute($doc, $parent, $attribName, $attribValue) {
    $attributeNode = $doc->createAttribute($attribName);
    $valueNode = $doc->createTextNode($attribValue);
    $attributeNode->appendChild($valueNode);
    $parent->appendChild($attributeNode);
  }

  private static function xml2Array(DOMDocument $xml) {
      $items = array();

      foreach($xml->getElementsByTagName("item") as $xml_item) {
          $item = array(
             "title" => self::getChildValue($xml_item, "title"),
             "link" => self::getChildValue($xml_item, "link"),
             "story_id" => self::getChildValue($xml_item, "harvard:WPID"),
             "author" => self::getChildValue($xml_item, "harvard:author"),
             "description" => self::getChildValue($xml_item, "description"),
             "unixtime" => strtotime(self::getChildValue($xml_item, "pubDate")),
             "featured" => self::isFeatured($xml_item),
             "body" => self::getChildValue($xml_item, "content:encoded"),
             "image" => self::getImage($xml_item),
           );


          $items[] = $item;
      }

      return $items;
  }

  private static function getFirstId(DOMDocument $xml) {
      $itemNodes = $xml->getElementsByTagName('item');
      if($itemNodes->length > 0) {
          return self::getChildValue($itemNodes->item(0), "harvard:WPID");
      }
  }

  private static function getLastId(DOMDocument $xml) {
      $itemNodes = $xml->getElementsByTagName('item');
      if($itemNodes->length > 0) {
          return self::getChildValue($itemNodes->item($itemNodes->length-1), "harvard:WPID");
      }
  }

  private static function getChildrenWithTag(DOMElement $xml, $tag) {
      $items = array();
      foreach($xml->childNodes as $item) {
          if($item->tagName == $tag) {
              $items[] = $item;
          }
      }
      return $items;
  }

  private static function getChildByTagName(DOMElement $xml, $tag) {
      $items = self::getChildrenWithTag($xml, $tag);
      if(count($items) == 1) {
          return $items[0];
      } else if(count($item) == 0) {
          throw new Exception("No elements with $tag found");
      } else {
          throw new Exception(count($items) . "with $tag found");
      }
  }

  private static function getChildValue(DOMElement $xml, $tag) {
      return self::getChildByTagName($xml, $tag)->nodeValue;
  }

  private static function isFeatured(DOMElement $xml) {
      $nodeValue = self::getChildValue($xml, "harvard:featured");

      if($nodeValue == "homepage" || $nodeValue == "category") {
          return true;
      }

      if($nodeValue == "no") {
          return false;
      }

      return false;
  }

  private static function getImage($xml_item) {
      $image_xml = self::getChildByTagName($xml_item, "image");

      return array(
          "title" => self::getChildValue($image_xml,  "title"),
          "link"  => self::getChildValue($image_xml,  "link"),
          "url"   => self::getChildValue($image_xml,  "url"),
          "width" => self::getChildValue($image_xml,  "width"),
          "height" => self::getChildValue($image_xml, "height"),
      );
  }
}

GazetteRSS::init();



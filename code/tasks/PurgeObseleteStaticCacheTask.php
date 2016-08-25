<?php
/**
 * Cleans up orphaned cache files that don't belong to any current live SiteTree page.
 * If there are any "special cases" e.g. blog tag pages which are static published but don't exist in SiteTree,
 * these can be excluded from this script using the Configuration system, for example:
 *
 *   PurgeObseleteStaticCacheTask:
 *     exclude:
 *       - '/^blog\/tag\//'
 *       - '/\.backup$/'
 *
 * Note that you need to escape forward slashes in your regular expressions and exclude the file extension (e.g. .html).
 */
class PurgeObseleteStaticCacheTask extends BuildTask {

	protected $description = 'Purge obselete: cleans up obselete/orphaned staticpublisher cache files';
	
	private static $memory_limit;
	
	public function __construct() {
		parent::__construct();
		if ($this->config()->get('disabled') === true) {
			$this->enabled = false ;
		}
	}

	function run($request) {
		// Setup task
		$dry = $request->getVar('dry') === '0' ? false : true;
		$actionStr = 'Deleted';
		if($dry){
			$actionStr = 'Would delete';
			self::msg('DRY RUN: add ?dry=0 to run for real');
		}
		ini_set('memory_limit', $this->config()->memory_limit);
		$oldMode = Versioned::get_reading_mode();
		Versioned::reading_stage('Live');
		
		
		// Get list of cacheable pages in the live SiteTree
		//  turn off save to database - we dont need to for this task
		StaticPagesQueue::config()->realtime = false;
		//  set our builder up
		$urls = new TemporaryURLArrayObject();
		$builder = SiteTreeFullBuildEngine::create();
		$builder->setUrlArrayObject($urls);
		//  run the builder to generate urls
		//   (we have to explicitly tell the builder to run normally, rather than in 
		//    a separate process, so we dont write urls to the database)
		$_GET['start'] = 0;
		$builder->config()->records_per_request = 100000;
		$builder->run($request);
		$pages = $urls->getUrls();
		
		// Browse files
		foreach(Config::inst()->get('SiteTree', 'extensions') as $extension) {
			if(preg_match('/FilesystemPublisher\(\'(\w+)\',\s?\'(\w+)\'\)/', $extension, $matches)) {
				$directory = BASE_PATH . '/' . $matches[1];
				$fileext = $matches[2];
				break;
			}
		}
		if(!isset($directory, $fileext)) die('FilesystemPublisher configuration not found.');

		// Get array of custom exclusion regexes from Config system
		$excludes = $this->config()->get('exclude');

		$removedURLPathMap = array();

		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
		$it->rewind();
		while($it->valid()) {

			$file_relative = $it->getSubPathName();

			// Get URL path from filename
			$urlpath = substr($file_relative, 0, strpos($file_relative, '.' . $fileext));

			// Exclude dot-files
			if($it->isDot()) {
				$it->next();
			}

			// Handle homepage special case
			if($file_relative == 'index.html' || $file_relative == 'index.php') $urlpath = '';

			// Exclude files that do not end in the file extension specified for FilesystemPublisher
			$length = strlen('.' . $fileext);
			if(substr($file_relative, -$length) != '.' . $fileext) {
				$it->next();
				continue;
			}

			// Exclude stale files (these are automatically deleted alongside the fresh file)
			$length = strlen('.stale.' . $fileext);
			if(substr($file_relative, -$length) == '.stale.' . $fileext) {
				$it->next();
				continue;
			}

			// Exclude files matching regexes supplied in the Config system
			if($excludes && is_array($excludes)) {
				foreach($excludes as $exclude) {
					if(preg_match($exclude, $urlpath)) {
						$it->next();
						continue 2;
					}
				}
			}

			// Exclude files for pages that exist in the SiteTree as well as known cacheable sub-pages
			// This array_intersect checks against any combination of leading and trailing slashes in the $pages values
			if(array_intersect(array($urlpath, $urlpath.'/', '/'.$urlpath, '/'.$urlpath.'/'), $pages)) {
				$it->next();
				continue;
			}
			
			// Exclude files that are simply redirects
			$redirectHeaderLine1 = '* This is a system-generated PHP script that performs header management for';
			$redirectHeaderLine2 = '* a 301 redirection.';
			$contents = file_get_contents($it->current()->getPathname());
			if(strstr($contents, $redirectHeaderLine1) !== false && strstr($contents, $redirectHeaderLine2) !== false){
				// TODO: find a better way of detecting redirection files?
				$it->next();
				continue;
			}
			
			// Remove
			$cacheFilePaths = SiteTreePublishingEngine::getAllCacheFilePaths($file_relative);
			foreach($cacheFilePaths as $cacheFilePath){
				$absCacheFilePath = $directory . '/' . $cacheFilePath;
				
				if(file_exists($absCacheFilePath)){
					self::msg(sprintf('%s: %s', $actionStr, $absCacheFilePath));
					if(!$dry){
						unlink($absCacheFilePath);
					}
				}else{
					// file not found, but we just saw it with the directory iterator... should never happen
				}
			}
			
			$removedURLPathMap[$urlpath] = $file_relative;
			
			$it->next();

		}
		
		
		self::msg($actionStr.' '.count($removedURLPathMap).' obselete pages from cache');

		Versioned::set_reading_mode($oldMode);
	}
	
	protected static function msg($str){
		Debug::message($str, false); // I don't like the header, personal preference
	}

}

/**
 * This class is needed to make sure we do not add to the build queue when purging.
 * TODO: there are methods you can use on the URLArrayObject and others to disable saving to db,
 * but it doesn't seem to work.
 */
class TemporaryURLArrayObject extends URLArrayObject {
	
	public function insertIntoDB(){
		return;
	}
	
	/**
	 * @return array of (urlSegment => priority)
	 */
	public function getUrlsAsArrayKeys(){
		$r = array();
		foreach($this->getArrayCopy() as $item){
			// 0 is priority
			// 1 is urlSegment
			$r[$item[1]] = $item[0];
		}
		return $r;
	}
	
	/**
	 * @return array of urlSegment
	 */
	public function getUrls(){
		return array_keys($this->getUrlsAsArrayKeys());
	}
	
}
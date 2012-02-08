<?php
/**
 * @package modx
 * @subpackage sources
 */
require_once MODX_CORE_PATH . 'model/modx/sources/modmediasource.class.php';
/**
 * Implements a Rackspace Cloud Files-based media source, allowing basic manipulation, uploading and URL-retrieval of resources
 * in a specified Cloud Files container.
 * 
 * @package modx
 * @subpackage sources
 */
class modCFMediaSource extends modMediaSource implements modMediaSourceInterface {
    /** @var RackSpace CF_Connection $driver */
    public $driver;
    /** @var RackSpace CF_Container $container */
    public $container;

    /**
     * Override the constructor to always force cf sources to not be streams.
     *
     * {@inheritDoc}
     *
     * @param xPDO $xpdo
     */
    public function __construct(xPDO & $xpdo) {
        parent::__construct($xpdo);
        $this->set('is_stream',false);
    }

    /**
     * Initializes CF media class, getting the CF driver and loading the container
     * @return boolean
     */
    public function initialize() {
        parent::initialize();
        $properties = $this->getPropertyList();
        if (!defined('CF_USERNAME')) {
            define('CF_USERNAME',$this->xpdo->getOption('username',$properties,''));
            define('CF_KEY',$this->xpdo->getOption('api_key',$properties,''));
				//define('CF_REGION',$this->xpdo->getOption('region',$$properties,''));
				define('CF_SERVICENET',$this->xpdo->getOption('servicenet',$properties,''));
        }
        include $this->xpdo->getOption('core_path',null,MODX_CORE_PATH).'model/cloudfiles/cloudfiles.php';

        $this->getDriver();
		  $this->validateOptions();
        $this->setContainer($this->xpdo->getOption('container',$properties,''));
        return true;
    }

    /**
     * Get the name of this source type
     * @return string
     */
    public function getTypeName() {
        $this->xpdo->lexicon->load('source');
        return $this->xpdo->lexicon('source_type.cf');
    }
    /**
     * Get the description of this source type
     * @return string
     */
    public function getTypeDescription() {
        $this->xpdo->lexicon->load('source');
        return $this->xpdo->lexicon('source_type.cf_desc');
    }


    /**
     * Gets the CF_Connection class instance
     * @return CF_Connection
     */
    public function getDriver() {
        if (empty($this->driver)) {
            try {
                $auth = new CF_Authentication(CF_USERNAME,CF_KEY);
 					 $auth->authenticate();

                $this->driver = new CF_Connection($auth, CF_SERVICENET);
            } catch (Exception $e) {
                $this->xpdo->log(modX::LOG_LEVEL_ERROR,'[modCloudFiles] Could not load CF_Connection class: '.$e->getMessage());
            }
        }
        return $this->driver;
    }

    /**
     * Checks the options and re-populates the url for the container
     * @return void
     */
    public function validateOptions() {
		 $properties = $this->getPropertyList();
		 
		 $containerName = $this->xpdo->getOption('container',$properties,'');
		 $url = $this->xpdo->getOption('url',$properties,'');
		 
		 try {
			 $containers = $this->driver->list_public_containers(true);
		 } catch (Exception $e) {
		 	$this->xpdo->log(modX::LOG_LEVEL_ERROR,'[modCloudFiles] Could not retreve list of containers, check username & api_key: '.$e->getMessage());
		 }
		 
		 if (!in_array($containerName, $containers) || empty($containerName)) {
		 	$this->xpdo->log(modX::LOG_LEVEL_ERROR,'[modCloudFiles] Container is not CDN enabled: '.$containerName);
		 $this->xpdo->setOption('container', '');
		 $this->setProperties(array(
			 'container' => array(
             'name' => 'container',
             'desc' => 'prop_cf.container_desc',
             'type' => 'textfield',
             'options' => '',
             'value' => '',
             'lexicon' => 'core:source',
         	 	)
			 ), true);
			
			return;
		 }
		 
		 try {
			 $container = $this->driver->get_container($containerName);
		 } catch (Exception $e) {
		 	$this->xpdo->log(modX::LOG_LEVEL_ERROR,'[modCloudFiles] Could not retreve container: '.$e->getMessage());
		 }
		 		 
		 $trimedUrl = trim(preg_replace('/^http:\/\//i', '', $url));
		 if (empty($trimedUrl)) {
			 $url = $container->cdn_uri."/";
			 $this->xpdo->log(modX::LOG_LEVEL_DEBUG,'[modCloudFiles] writing new url: '.$url);
			 $this->xpdo->setOption('url', $url);
			 $this->setProperties(array(
				 'url' => array(
              'name' => 'url',
              'desc' => 'prop_cf.url_desc',
              'type' => 'textfield',
              'options' => '',
              'value' => $url,
              'lexicon' => 'core:source',
          	 	)
				 ), true);
			 $this->save();
				 
		}
    }	


    /**
     * Set the container for the connection to CF
     * @param string $container
     * @return void
     */
    public function setContainer($container) {
		  try {
			  $this->container = $this->driver->get_container($container);
	  	  } catch (Exception $e) {
			  $this->xpdo->log(modX::LOG_LEVEL_ERROR,'[modCloudFiles] Could not get container: '.$e->getMessage());
		  }
    }

    /**
     * Get a list of objects from within a container
     * @param string $dir
     * @return array
     */
    public function getCFObjectList($dir) {
        $prefix = (!empty($dir) && $dir != '/') ? $dir : NULL;

        $list = $this->container->list_objects(0, NULL, NULL, $prefix);
		  
		  /* filter returns */
		  if (is_null($prefix) || empty($prefix)) {
			   foreach($list as $i=>$itm) {
			  	 	if (preg_match('/\/.+$/', $itm)){
						unset($list[$i]);
					}
		  		}
	  		}
        return $list;
    }

    /**
     * @param string $path
     * @return array
     */
    public function getContainerList($path) {
        $properties = $this->getPropertyList();
        $list = $this->getCFObjectList($path);

        $useMultiByte = $this->ctx->getOption('use_multibyte', false);
        $encoding = $this->ctx->getOption('modx_charset', 'UTF-8');

        $directories = array();
        $files = array();
        foreach ($list as $idx => $currentPath) {
            if ($currentPath == $path) continue;
            $fileName = basename($currentPath);
            $isDir = substr(strrev($currentPath),0,1) === '/';

            $extension = pathinfo($fileName,PATHINFO_EXTENSION);
            $extension = $useMultiByte ? mb_strtolower($extension,$encoding) : strtolower($extension);

            $relativePath = $currentPath == '/' ? $currentPath : str_replace($path,'',$currentPath);
            $slashCount = substr_count($relativePath,'/');
            if (($slashCount > 1 && $isDir) || ($slashCount > 0 && !$isDir)) {
                continue;
            }
            if ($isDir) {
                $directories[$currentPath] = array(
                    'id' => $currentPath,
                    'text' => $fileName,
                    'cls' => 'icon-'.$extension,
                    'type' => 'dir',
                    'leaf' => false,
                    'path' => $currentPath,
                    'pathRelative' => $currentPath,
                    'perms' => '',
                );
                $directories[$currentPath]['menu'] = array('items' => $this->getListContextMenu($currentPath,$isDir,$directories[$currentPath]));
            } else {
                $files[$currentPath] = array(
                    'id' => $currentPath,
                    'text' => $fileName,
                    'cls' => 'icon-'.$extension,
                    'type' => 'file',
                    'leaf' => true,
                    'path' => $currentPath,
                    'pathRelative' => $currentPath,
                    'directory' => $currentPath,
                    'url' => rtrim($properties['url'],'/').'/'.$currentPath,
                    'file' => $currentPath,
                );
                $files[$currentPath]['menu'] = array('items' => $this->getListContextMenu($currentPath,$isDir,$files[$currentPath]));
            }
        }

        $ls = array();
        /* now sort files/directories */
        ksort($directories);
        foreach ($directories as $dir) {
            $ls[] = $dir;
        }
        ksort($files);
        foreach ($files as $file) {
            $ls[] = $file;
        }

        return $ls;
    }

    /**
     * Get the context menu for when viewing the source as a tree
     * 
     * @param string $file
     * @param boolean $isDir
     * @param array $fileArray
     * @return array
     */
    public function getListContextMenu($file,$isDir,array $fileArray) {
        $menu = array();
        if (!$isDir) { /* files */
            if ($this->hasPermission('file_update')) {
                $menu[] = array(
                    'text' => $this->xpdo->lexicon('rename'),
                    'handler' => 'this.renameFile',
                );
            }
            if ($this->hasPermission('file_view')) {
                $menu[] = array(
                    'text' => $this->xpdo->lexicon('file_download'),
                    'handler' => 'this.downloadFile',
                );
            }
            if ($this->hasPermission('file_remove')) {
                if (!empty($menu)) $menu[] = '-';
                $menu[] = array(
                    'text' => $this->xpdo->lexicon('file_remove'),
                    'handler' => 'this.removeFile',
                );
            }
        } else { /* directories */
            if ($this->hasPermission('directory_create')) {
                $menu[] = array(
                    'text' => $this->xpdo->lexicon('file_folder_create_here'),
                    'handler' => 'this.createDirectory',
                );
            }
            $menu[] = array(
                'text' => $this->xpdo->lexicon('directory_refresh'),
                'handler' => 'this.refreshActiveNode',
            );
            if ($this->hasPermission('file_upload')) {
                $menu[] = '-';
                $menu[] = array(
                    'text' => $this->xpdo->lexicon('upload_files'),
                    'handler' => 'this.uploadFiles',
                );
            }
            if ($this->hasPermission('directory_remove')) {
                $menu[] = '-';
                $menu[] = array(
                    'text' => $this->xpdo->lexicon('file_folder_remove'),
                    'handler' => 'this.removeDirectory',
                );
            }
        }
        return $menu;
    }

    /**
     * Get all files in the directory and prepare thumbnail views
     * 
     * @param string $path
     * @return array
     */
    public function getObjectsInContainer($path) {
        $list = $this->getCFObjectList($path);
        $properties = $this->getPropertyList();

        $modAuth = $this->xpdo->user->getUserToken($this->xpdo->context->get('key'));

        /* get default settings */
        $use_multibyte = $this->ctx->getOption('use_multibyte', false);
        $encoding = $this->ctx->getOption('modx_charset', 'UTF-8');
        $containerUrl = rtrim($properties['url'],'/').'/';
        $allowedFileTypes = $this->getOption('allowedFileTypes',$this->properties,'');
        $allowedFileTypes = !empty($allowedFileTypes) && is_string($allowedFileTypes) ? explode(',',$allowedFileTypes) : $allowedFileTypes;
        $imageExtensions = $this->getOption('imageExtensions',$this->properties,'jpg,jpeg,png,gif');
        $imageExtensions = explode(',',$imageExtensions);
        $thumbnailType = $this->getOption('thumbnailType',$this->properties,'png');
        $thumbnailQuality = $this->getOption('thumbnailQuality',$this->properties,90);
        $skipFiles = $this->getOption('skipFiles',$this->properties,'.svn,.git,_notes,.DS_Store');
        $skipFiles = explode(',',$skipFiles);
        $skipFiles[] = '.';
        $skipFiles[] = '..';

        /* iterate */
        $files = array();
        foreach ($list as $object) {
            $objectUrl = $containerUrl.trim($object,'/');
            $baseName = basename($object);
            $isDir = substr(strrev($object),0,1) == '/' ? true : false;
            if (in_array($object,$skipFiles)) continue;

            if (!$isDir) {
                $fileArray = array(
                    'id' => $object,
                    'name' => $baseName,
                    'url' => $objectUrl,
                    'relativeUrl' => $objectUrl,
                    'fullRelativeUrl' => $objectUrl,
                    'pathname' => $objectUrl,
                    'size' => 0,
                    'leaf' => true,
                    'menu' => array(
                        array('text' => $this->xpdo->lexicon('file_remove'),'handler' => 'this.removeFile'),
                    ),
                );

                $fileArray['ext'] = pathinfo($baseName,PATHINFO_EXTENSION);
                $fileArray['ext'] = $use_multibyte ? mb_strtolower($fileArray['ext'],$encoding) : strtolower($fileArray['ext']);
                $fileArray['cls'] = 'icon-'.$fileArray['ext'];

                if (!empty($allowedFileTypes) && !in_array($fileArray['ext'],$allowedFileTypes)) continue;

                /* get thumbnail */
                if (in_array($fileArray['ext'],$imageExtensions)) {
                    $imageWidth = $this->ctx->getOption('filemanager_image_width', 400);
                    $imageHeight = $this->ctx->getOption('filemanager_image_height', 300);
                    $thumbHeight = $this->ctx->getOption('filemanager_thumb_height', 60);
                    $thumbWidth = $this->ctx->getOption('filemanager_thumb_width', 80);

                    $size = @getimagesize($objectUrl);
                    if (is_array($size)) {
                        $imageWidth = $size[0] > 800 ? 800 : $size[0];
                        $imageHeight = $size[1] > 600 ? 600 : $size[1];
                    }

                    /* ensure max h/w */
                    if ($thumbWidth > $imageWidth) $thumbWidth = $imageWidth;
                    if ($thumbHeight > $imageHeight) $thumbHeight = $imageHeight;

                    /* generate thumb/image URLs */
                    $thumbQuery = http_build_query(array(
                        'src' => $object,
                        'w' => $thumbWidth,
                        'h' => $thumbHeight,
                        'f' => $thumbnailType,
                        'q' => $thumbnailQuality,
                        'HTTP_MODAUTH' => $modAuth,
                        'wctx' => $this->ctx->get('key'),
                        'source' => $this->get('id'),
                    ));
                    $imageQuery = http_build_query(array(
                        'src' => $object,
                        'w' => $imageWidth,
                        'h' => $imageHeight,
                        'HTTP_MODAUTH' => $modAuth,
                        'f' => $thumbnailType,
                        'q' => $thumbnailQuality,
                        'wctx' => $this->ctx->get('key'),
                        'source' => $this->get('id'),
                    ));
                    $fileArray['thumb'] = $this->ctx->getOption('connectors_url', MODX_CONNECTORS_URL).'system/phpthumb.php?'.urldecode($thumbQuery);
                    $fileArray['image'] = $this->ctx->getOption('connectors_url', MODX_CONNECTORS_URL).'system/phpthumb.php?'.urldecode($imageQuery);

                } else {
                    $fileArray['thumb'] = $this->ctx->getOption('manager_url', MODX_MANAGER_URL).'templates/default/images/restyle/nopreview.jpg';
                    $fileArray['thumbWidth'] = $this->ctx->getOption('filemanager_thumb_width', 80);
                    $fileArray['thumbHeight'] = $this->ctx->getOption('filemanager_thumb_height', 60);
                }
                $files[] = $fileArray;
            }
        }
        return $files;
    }

    /**
     * Create a Container
     *
     * @param string $name
     * @param string $parentContainer
     * @return boolean
     */
    public function createContainer($name,$parentContainer) {
        $newPath = $parentContainer.rtrim(str_replace(' ','-',$name),'/').'/';
		  
		  /* if the first character is a '/' the SDK will just throw an error */
		  if ($newPath[0]=='/') { $newPath = substr($newPath, 1); }
		  
        /* check to see if folder already exists */
        try {
		  		$newContainer = new CF_Object($this->container, $newPath, False);
			} catch (Exception $e) {
				//$this->xpdo->log(modX::LOG_LEVEL_ERROR,'[modCloudFiles] Directory does not exsist: '.$e->getMessage());
            $this->addError('file',$this->xpdo->lexicon('file_folder_err_ae').': /'.$newPath);
            return false;
				
			}

        /* create empty file that acts as folder */
        try {
				if (is_object($newContainer) && get_class($newContainer) == "CF_Object") {
	            $newContainer->content_type = "application/directory";
	            $newContainer->write(".", 1);
				} else {
					$this->container->create_paths($newPath."/");
				}
				
	  		} catch (Exception $e) {
            $this->addError('name',$this->xpdo->lexicon('file_folder_err_create').'/'.$newPath);
            return false;
			}
			
        $this->xpdo->logManagerAction('directory_create','','/'.$newPath);
        return true;
    }

    /**
     * Remove an empty folder from CF
     *
     * @param $path
     * @return boolean
     */
    public function removeContainer($path) {
		 
	  /* if the first character is a '/' the SDK will just throw an error */
	  if ($path[0]=='/') { $path = substr($path, 1); }
		 
		 try {
			 $obj = new CF_Object($this->container, $path, False);
		 } catch (Exception $e) {
          $this->addError('file',$this->xpdo->lexicon('file_folder_err_ns').': /'.$path);
          return false;
		 }

        /* remove file from cf */
		  try {
			  $this->container->delete_object($obj);
		  } catch (Exception $e) {
			  $this->addError('file',$this->xpdo->lexicon('file_folder_err_ns').': /'.$path);
			  return false;
		  }

        /* log manager action */
        $this->xpdo->logManagerAction('directory_remove','',$path);

        return true;
    }


    /**
     * Delete a file from CF
     * 
     * @param string $objectPath
     * @return boolean
     */
    public function removeObject($objectPath) {
		 
  	 	 /* if the first character is a '/' the SDK will just throw an error */
  	 	 if ($objectPath[0]=='/') { $objectPath = substr($objectPath, 1); }
		 
		 try {
			 $obj = new CF_Object($this->container, $objectPath, False);
		 } catch (Exception $e) {
          $this->addError('file',$this->xpdo->lexicon('file_folder_err_ns').': '.$objectPath);
          return false;
		 }

        /* remove file from CF */
		  try {
			  $this->container->delete_object($obj);
		  } catch (Exception $e) {
			  $this->addError('file',$this->xpdo->lexicon('file_folder_err_ns').': '.$objectPath);
			  return false;
		  }

        /* log manager action */
        $this->xpdo->logManagerAction('file_remove','',$objectPath);

        return true;
    }

    /**
     * Rename/move a file
     * 
     * @param string $oldPath
     * @param string $newName
     * @return bool
     */
    public function renameObject($oldPath,$newName) {
		 
    	  /* if the first character is a '/' the SDK will just throw an error */
    	  if ($oldPath[0]=='/') { $oldPath = substr($oldPath, 1); }
		 
		 try {
			 $obj = new CF_Object($this->container, $oldPath, False);
		 } catch (Exception $e) {
          $this->addError('file',$this->xpdo->lexicon('file_folder_err_ns').': /'.$oldPath);
          return false;
		 }
		
        $dir = dirname($oldPath);
        $newPath = ($dir != '.' ? $dir.'/' : '').str_replace(' ','-',$newName);
	  
		  try {
			  $this->container->move_object_to($oldPath, $this->container, $newPath);
		  } catch (Exception $e) {
			  //$this->xpdo->log(modX::LOG_LEVEL_ERROR,'[modCloudFiles] move error: '.$e->getMessage());
           $this->addError('file',$this->xpdo->lexicon('file_folder_err_rename').': /'.$oldPath);
           return false;
		  }

        $this->xpdo->logManagerAction('file_rename','','/'.$oldPath);
        return true;
    }

    /**
     * Upload files to CF
     * 
     * @param string $container
     * @param array $objects
     * @return bool
     */
    public function uploadObjectsToContainer($container,array $objects = array()) {
        if ($container == '/' || $container == '.') $container = '';

        $allowedFileTypes = explode(',',$this->xpdo->getOption('upload_files',null,''));
        $allowedFileTypes = array_merge(explode(',',$this->xpdo->getOption('upload_images')),explode(',',$this->xpdo->getOption('upload_media')),explode(',',$this->xpdo->getOption('upload_flash')),$allowedFileTypes);
        $allowedFileTypes = array_unique($allowedFileTypes);
        $maxFileSize = $this->xpdo->getOption('upload_maxsize',null,1048576);

        /* loop through each file and upload */
        foreach ($objects as $file) {
            if ($file['error'] != 0) continue;
            if (empty($file['name'])) continue;
            $ext = @pathinfo($file['name'],PATHINFO_EXTENSION);
            $ext = strtolower($ext);

            if (empty($ext) || !in_array($ext,$allowedFileTypes)) {
                $this->addError('path',$this->xpdo->lexicon('file_err_ext_not_allowed',array(
                    'ext' => $ext,
                )));
                continue;
            }
            $size = @filesize($file['tmp_name']);

            if ($size > $maxFileSize) {
                $this->addError('path',$this->xpdo->lexicon('file_err_too_large',array(
                    'size' => $size,
                    'allowed' => $maxFileSize,
                )));
                continue;
            }

            $newPath = $container.str_replace(' ','-',$file['name']);


            try {
					//$this->container->create_paths($newPath); shouldn't have this problem
					$fileObj = $this->container->create_object($newPath);
					$fileObj->load_from_filename($file['tmp_name']);
				} catch (Exception $e) {
					$this->addError('path',$this->xpdo->lexicon('file_err_upload'));
				}
				
        }

        /* invoke event */
        $this->xpdo->invokeEvent('OnFileManagerUpload',array(
            'files' => &$objects,
            'directory' => $container,
            'source' => &$this,
        ));

        $this->xpdo->logManagerAction('file_upload','',$container);

        return true;
    }

 
    /**
     * Move a file or folder to a specific location
     *
     * @param string $from The location to move from
     * @param string $to The location to move to
     * @param string $point
     * @return boolean
     */
    public function moveObject($from,$to,$point = 'append') {
        $this->xpdo->lexicon->load('source');
        $success = false;
		  
    	  /* if the first character is a '/' the SDK will just throw an error */
    	  if ($from[0]=='/') { $from = substr($from, 1); }
		  if ($to[0]=='/') { $to = substr($to, 1); }
		  

        if (substr(strrev($from),0,1) == '/') {
            $this->xpdo->error->message = $this->xpdo->lexicon('cf_no_move_folder',array(
                'from' => $from
            ));
            return $success;
        }

		  try {
			  $objFrom = new CF_Object($this->container, $from, False);
		  } catch (Exception $e) {
			  //$this->xpdo->log(modX::LOG_LEVEL_ERROR,'[modCloudFiles] Move From: '.$e->getMessage());
           $this->xpdo->error->message = $this->xpdo->lexicon('file_err_ns').': '.$from;
           return $success;
		  }
		  
		  
        if ($to != '/') {
			  try {
				  $objTo = new CF_Object($this->container, $to, False);
			  } catch (Exception $e) {
			  	//$this->xpdo->log(modX::LOG_LEVEL_ERROR,'[modCloudFiles] Move To destination: '.$e->getMessage());
			  }
			  /*$this->xpdo->error->message = $this->xpdo->lexicon('file_err_ns').': '.$to;
              return $success;*/
            $toPath = rtrim($to,'/').'/'.basename($from);
        } else {
            $toPath = basename($from);
        }
        
		  
		  try {
			  $this->container->move_object_to($objFrom, $this->container, $toPath);
			  $success = true;
		  } catch (Exception $e) {
			  	//$this->xpdo->log(modX::LOG_LEVEL_ERROR,'[modCloudFiles] move: '.$e->getMessage());
		  		$this->xpdo->error->message = $this->xpdo->lexicon('file_folder_err_rename').': '.$to.' -> '.$from;
		  }

        return $success;
    }

    /**
     * @return array
     */
    public function getDefaultProperties() {
        return array(
            'username' => array(
                'name' => 'username',
                'desc' => 'prop_cf.username_desc',
                'type' => 'textfield',
                'options' => '',
                'value' => '',
                'lexicon' => 'core:source',
            ),
            'api_key' => array(
                'name' => 'api_key',
                'desc' => 'prop_cf.key_desc',
                'type' => 'password',
                'options' => '',
                'value' => '',
                'lexicon' => 'core:source',
            ),
            'servicenet' => array(
                'name' => 'servicenet',
                'desc' => 'prop_cf.servicenet_desc',
                'type' => 'combo-boolean',
                'options' => '',
                'value' => false,
                'lexicon' => 'core:source',
            ),
            'container' => array(
                'name' => 'container',
                'desc' => 'prop_cf.container_desc',
                'type' => 'textfield',
                'options' => '',
                'value' => '',
                'lexicon' => 'core:source',
            ),
				'url' => array(
                'name' => 'url',
                'desc' => 'prop_cf.url_desc',
                'type' => 'textfield',
                'options' => '',
                'value' => 'http://',
                'lexicon' => 'core:source',
            ),
            'imageExtensions' => array(
                'name' => 'imageExtensions',
                'desc' => 'prop_s3.imageExtensions_desc',
                'type' => 'textfield',
                'value' => 'jpg,jpeg,png,gif',
                'lexicon' => 'core:source',
            ),
            'thumbnailType' => array(
                'name' => 'thumbnailType',
                'desc' => 'prop_s3.thumbnailType_desc',
                'type' => 'list',
                'options' => array(
                    array('name' => 'PNG','value' => 'png'),
                    array('name' => 'JPG','value' => 'jpg'),
                    array('name' => 'GIF','value' => 'gif'),
                ),
                'value' => 'png',
                'lexicon' => 'core:source',
            ),
            'thumbnailQuality' => array(
                'name' => 'thumbnailQuality',
                'desc' => 'prop_s3.thumbnailQuality_desc',
                'type' => 'textfield',
                'options' => '',
                'value' => 90,
                'lexicon' => 'core:source',
            ),
            'skipFiles' => array(
                'name' => 'skipFiles',
                'desc' => 'prop_s3.skipFiles_desc',
                'type' => 'textfield',
                'options' => '',
                'value' => '.svn,.git,_notes,nbproject,.idea,.DS_Store',
                'lexicon' => 'core:source',
            ),
        );
    }

    /**
     * Prepare a src parameter to be rendered with phpThumb
     * 
     * @param string $src
     * @return string
     */
    public function prepareSrcForThumb($src) {
        $properties = $this->getPropertyList();
        if (strpos($src,$properties['url']) === false) {
            $src = $properties['url'].ltrim($src,'/');
        }
        return $src;
    }

    /**
     * Get the base URL for this source. Only applicable to sources that are streams.
     *
     * @param string $object An optional object to find the base url of
     * @return string
     */
    public function getBaseUrl($object = '') {
        $properties = $this->getPropertyList();
        return $properties['url'];
    }

    /**
     * Get the absolute URL for a specified object. Only applicable to sources that are streams.
     *
     * @param string $object
     * @return string
     */
    public function getObjectUrl($object = '') {
        $properties = $this->getPropertyList();
        return $properties['url'].$object;
    }


    /**
     * Get the contents of a specified file
     *
     * @param string $objectPath
     * @return array
     */
    public function getObjectContents($objectPath) {
        $properties = $this->getPropertyList();
        $objectUrl = $properties['url'].$objectPath;
        $contents = @file_get_contents($objectUrl);

        $imageExtensions = $this->getOption('imageExtensions',$this->properties,'jpg,jpeg,png,gif');
        $imageExtensions = explode(',',$imageExtensions);
        $fileExtension = pathinfo($objectPath,PATHINFO_EXTENSION);
        
        return array(
            'name' => $objectPath,
            'basename' => basename($objectPath),
            'path' => $objectPath,
            'size' => '',
            'last_accessed' => '',
            'last_modified' => '',
            'content' => $contents,
            'image' => in_array($fileExtension,$imageExtensions) ? true : false,
            'is_writable' => false,
            'is_readable' => false,
        );
    }
}

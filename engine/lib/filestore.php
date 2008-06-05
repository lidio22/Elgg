<?php
	/**
	 * Elgg filestore.
	 * This file contains classes, interfaces and functions for saving and retrieving data to various file 
	 * stores.
	 * 
	 * @package Elgg
	 * @subpackage API
	 * @license http://www.gnu.org/licenses/old-licenses/gpl-2.0.html GNU Public License version 2
	 * @author Marcus Povey
	 * @copyright Curverider Ltd 2008
	 * @link http://elgg.org/
	 */

	include_once("objects.php");
	
	/**
	 * @class ElggFilestore
	 * This class defines the interface for all elgg data repositories.
	 * @author Marcus Povey
	 */
	abstract class ElggFilestore
	{
		/**
		 * Attempt to open the file $file for storage or writing.
		 *
		 * @param ElggFile $file
		 * @param string $mode "read", "write", "append"
		 * @return mixed A handle to the opened file or false on error.
		 */
		abstract public function open(ElggFile $file, $mode);
		
		/**
		 * Write data to a given file handle.
		 *
		 * @param mixed $f The file handle - exactly what this is depends on the file system
		 * @param string $data The binary string of data to write
		 * @return int Number of bytes written.
		 */
		abstract public function write($f, $data);
		
		/**
		 * Read data from a filestore.
		 *
		 * @param mixed $f The file handle
		 * @param int $length Length in bytes to read.
		 * @param int $offset The optional offset.
		 * @return mixed String of data or false on error.
		 */
		abstract public function read($f, $length, $offset = 0);
		
		/**
		 * Seek a given position within a file handle.
		 * 
		 * @param mixed $f The file handle.
		 * @param int $position The position.
		 */
		abstract public function seek($f, $position);
		
		/**
		 * Return a whether the end of a file has been reached.
		 * 
		 * @param mixed $f The file handle.
		 * @return boolean
		 */
		abstract public function eof($f);
		
		/**
		 * Return the current position in an open file.
		 * 
		 * @param mixed $f The file handle.
		 * @return int
		 */
		abstract public function tell($f);
		
		/**
		 * Close a given file handle.
		 *
		 * @param mixed $f
		 */
		abstract public function close($f);
		
		/**
		 * Delete the file associated with a given file handle.
		 *
		 * @param ElggFile $file
		 */
		abstract public function delete(ElggFile $file);
		
		/**
		 * Return the size in bytes for a given file.
		 * 
		 * @param ElggFile $file
		 */
		abstract public function getFileSize(ElggFile $file);
		
		/**
		 * Return the filename of a given file as stored on the filestore.
		 * 
		 * @param ElggFile $file
		 */
		abstract public function getFilenameOnFilestore(ElggFile $file);
		
		/**
		 * Get the filestore's creation parameters as an associative array.
		 * Used for serialisation and for storing the creation details along side a file object.
		 * 
		 * @return array
		 */
		abstract public function getParameters();
		
		/**
		 * Set the parameters from the associative array produced by $this->getParameters().
		 */
		abstract public function setParameters(array $parameters);

	}
	
	/**
	 * @class ElggDiskFilestore
	 * This class uses disk storage to save data.
	 * @author Marcus Povey
	 */
	class ElggDiskFilestore extends ElggFilestore
	{
		/**
		 * Directory root.
		 */
		private $dir_root;
		
		/**
		 * Default depth of file directory matrix
		 */
		private $matrix_depth = 5;
		
		/**
		 * Construct a disk filestore using the given directory root.
		 *
		 * @param string $directory_root Root directory, must end in "/"
		 */
		public function __construct($directory_root)
		{
			$this->dir_root = $directory_root;
		}
		
		public function open(ElggFile $file, $mode)
		{
			$fullname = $this->getFilenameOnFilestore($file);
			
			// Split into path and name
			$ls = strrpos($fullname,"/");
			if ($ls===false) $ls = 0;
			
			$path = substr($fullname, 0, $ls);
			$name = substr($fullname, $ls); 
			
			// Try and create the directory
			try { $this->make_directory_root($path); } catch (Exception $e){}
			
			switch ($mode)
			{
				case "read" : $mode = "r+b"; break;
				case "write" : $mode = "w+b"; break;
				case "append" : $mode = "a+b"; break;
				default: throw new InvalidParameterException(sprintf(elgg_echo('InvalidParameterException:UnrecognisedFileMode'), $mode));
			}
			
			return fopen($fullname, $mode);
		}
		
		public function write($f, $data)
		{
			return fwrite($f, $data);
		}
		
		public function read($f, $length, $offset = 0)
		{
			if ($offset)
				$this->seek($f, $offset);
				
			return fread($f, $length);
		}
		
		public function close($f)
		{
			return fclose($f);
		}
		
		public function delete(ElggFile $file)
		{
			$unlink = unlink($this->getFilenameOnFilestore($file));
			if ($unlink)
				return $file->delete();
	
			return false;
		}
		
		public function seek($f, $position)
		{
			return fseek($f, $position);
		}
		
		public function tell($f)
		{
			return ftell($f);
		}
		
		public function eof($f)
		{
			return feof($f);
		}
		
		public function getFileSize(ElggFile $file)
		{			
			return filesize($this->getFilenameOnFilestore($file));
		}
	
		public function getFilenameOnFilestore(ElggFile $file)
		{
			$owner = $file->getOwnerEntity();
			if (!$owner)
				$owner = $_SESSION['user'];
					
			if ((!$owner) || (!$owner->username)) throw InvalidParameterException("All files must have an owner!");
			
			return $this->dir_root . $this->make_file_matrix($owner->username) . $file->getFilename();
		}
		
		/**
		 * Make the directory root.
		 *
		 * @param string $dirroot
		 */
		protected function make_directory_root($dirroot)
		{
			if (!@mkdir($dirroot, 0700, true)) 
				throw new IOException("Could not make $dirroot");
				
			return true;
		}
		
		/**
		 * Construct the filename matrix.
		 *
		 * @param string $filename
		 */
		protected function make_file_matrix($filename)
		{
			$matrix = "";
			
			$len = strlen($filename);
			if ($len>$this->matrix_depth)
				$len = $this->matrix_depth;
			
			for ($n = 0; $n < $len; $n++) {
				if (ctype_alnum($filename[$n]))
					$matrix .= $filename[$n] . "/";
			}	
	
			return $matrix.$filename."/";
		}
		
		public function getParameters()
		{
			return array("dir_root" => $this->dir_root);
		}
		
		public function setParameters(array $parameters)
		{
			if (isset($parameters['dir_root']))
			{
				$this->dir_root = $parameters['dir_root'];
				return true;
			}
			
			return false;
		}
	}
	
	/**
	 * @class ElggFile
	 * This class represents a physical file.
	 * 
	 * Usage:
	 *		Create a new ElggFile object and specify a filename, and optionally a FileStore (if one isn't specified 
	 *		then the default is assumed.
	 * 
	 * 		Open the file using the appropriate mode, and you will be able to read and write to the file.
	 * 
	 * 		Optionally, you can also call the file's save() method, this will turn the file into an entity in the 
	 * 		system and permit you to do things like attach tags to the file etc. This is not done automatically since
	 * 		there are many occasions where you may want access to file data on datastores using the ElggFile interface
	 * 		but do not want to create an Entity reference to it in the system (temporary files for example).
	 * 
	 * @author Marcus Povey
	 */
	class ElggFile extends ElggObject
	{
		/** Filestore */
		private $filestore;
		
		/** File handle used to identify this file in a filestore. Created by open. */
		private $handle;
		
		protected function initialise_attributes()
		{
			parent::initialise_attributes();
			
			$this->attributes['subtype'] = "file";
		}
		
		public function __construct($guid = null) 
		{			
			parent::__construct($guid);
			
			// Set default filestore
			$this->filestore = $this->getFilestore();
		}
		
		/**
		 * Set the filename of this file.
		 * 
		 * @param string $name The filename.
		 */
		public function setFilename($name) { $this->filename = $name; }
		
		/**
		 * Return the filename.
		 */
		public function getFilename() { return $this->filename; }
		
		/**
		 * Return the filename of this file as it is/will be stored on the filestore, which may be different
		 * to the filename.
		 */
		public function getFilenameOnFilestore() { return $this->filestore->getFilenameOnFilestore($this); }
		
		/**
		 * Get the mime type of the file.
		 */
		public function getMimeType() 
		{
			if ($this->mimetype)
				return $this->mimetype;
				
			// TODO : Guess mimetype if not here
		}
		
		/**
		 * Set the mime type of the file.
		 * 
		 * @param $mimetype The mimetype
		 */
		public function setMimeType($mimetype) { return $this->mimetype = $mimetype; }
		
		/**
		 * Set the optional file description.
		 * 
		 * @param string $description The description.
		 */
		public function setDescription($description) { $this->description = $description; }
		
		/**
		 * Open the file with the given mode
		 * 
		 * @param string $mode Either read/write/append
		 */
		public function open($mode)
		{
			if (!$this->getFilename())
				throw new IOException("You must specify a name before opening a file.");
				
			// See if file has already been saved
				// seek on datastore, parameters and name?
			
			// Sanity check
			if (
				($mode!="read") &&
				($mode!="write") &&
				($mode!="append")
			)
				throw new InvalidParameterException("Unrecognised file mode '$mode'");
			
			// Get the filestore
			$fs = $this->getFilestore();
			
			// Ensure that we save the file details to object store
			//$this->save();
			
			// Open the file handle
			$this->handle = $fs->open($this, $mode);
			
			return $this->handle;
		}
		
		/**
		 * Write some data.
		 * 
		 * @param string $data The data
		 */
		public function write($data)
		{
			$fs = $this->getFilestore();
			
			return $fs->write($this->handle, $data);
		}
		
		/**
		 * Read some data.
		 * 
		 * @param int $length Amount to read.
		 * @param int $offset The offset to start from.
		 */
		public function read($length, $offset = 0)
		{
			$fs = $this->getFilestore();
			
			return $fs->read($this->handle, $length, $offset);
		}
		
		/**
		 * Close the file and commit changes
		 */
		public function close()
		{
			$fs = $this->getFilestore();
			
			if ($fs->close($this->handle))
			{
				$this->handle = NULL;
				
				return true;
			}
			
			return false;
		}
		
		/**
		 * Delete this file.
		 */
		public function delete()
		{
			$fs = $this->getFilestore();
			
			return $fs->delete($this);
		}
		
		/**
		 * Seek a position in the file.
		 * 
		 * @param int $position
		 */
		public function seek($position)
		{
			$fs = $this->getFilestore();
			
			return $fs->seek($this->handle, $position);
		}
		
		/**
		 * Return the current position of the file.
		 * 
		 * @return int The file position
		 */
		public function tell()
		{
			$fs = $this->getFilestore();
			
			return $fs->tell($this->handle);
		}
	
		/**
		 * Return the size of the file in bytes.
		 */
		public function size()
		{
			return $this->filestore->getFileSize($this);
		}
		
		/**
		 * Return a boolean value whether the file handle is at the end of the file
		 */
		public function eof()
		{
			$fs = $this->getFilestore();
			
			return $fs->eof($this->handle);
		}
		
		/**
		 * Set a filestore.
		 * 
		 * @param ElggFilestore $filestore The file store.
		 */
		public function setFilestore(ElggFilestore $filestore)
		{
			$this->filestore = $filestore;	
		}
		
		/**
		 * Return a filestore suitable for saving this file.
		 * This filestore is either a pre-registered filestore, a filestore loaded from metatags saved
		 * along side this file, or the system default.
		 */
		protected function getFilestore()
		{
			// Short circuit if already set.
			if ($this->filestore)
				return $this->filestore;
				
			
			// If filestore meta set then retrieve filestore TODO: Better way of doing this?
			$metas = get_metadata_for_entity($this->guid);
			$parameters = array();
			foreach ($metas as $meta)
			{
				if (strpos($meta->name, "filestore::")!==false)
				{
					// Filestore parameter tag
					$comp = explode("::", $meta->name);
					$name = $comp[1]; 
			
					$parameters[$name] = $meta->value;
				}
			}
		
			// If parameters loaded then create new filestore
			if (count($parameters)!=0)
			{
				// Create new filestore object
				if ((!isset($parameters['filestore'])) || (!class_exists($parameters['filestore'])))
					throw new ClassNotFoundException("Filestore not found or class not saved with file!");
					
				$this->filestore = new $parameters['filestore']();

				// Set parameters
				$this->filestore->setParameters($parameters);
			}
			

			// if still nothing then set filestore to default
			if (!$this->filestore)
				$this->filestore = get_default_filestore();

			return $this->filestore;
		}
		
		public function save()
		{
			if (!parent::save())
				return false;
				
			// Save datastore metadata
			$params = $this->filestore->getParameters();
			foreach ($params as $k => $v)
				$this->setMetaData("filestore::$k", $v);
			
			// Now make a note of the filestore class
			$this->setMetaData("filestore::filestore", get_class($this->filestore));
			
			return true;
		}
		
	}
	
	/**
	 * Get the contents of an uploaded file.
	 * (Returns false if there was an issue.)
	 *
	 * @param string $input_name The name of the file input field on the submission form
	 * @return mixed|false The contents of the file, or false on failure.
	 */
	function get_uploaded_file($input_name) {
		
		// If the file exists ...
		if (isset($_FILES[$input_name]) && $_FILES[$input_name]['error'] == 0) {
			return file_get_contents($_FILES[$input_name]['tmp_name']);
		}
		return false;
		
	}
	
	/**
	 * Gets the jpeg contents of the resized version of an uploaded image 
	 * (Returns false if the uploaded file was not an image)
	 *
	 * @param string $input_name The name of the file input field on the submission form
	 * @param int $maxwidth The maximum width of the resized image
	 * @param int $maxheight The maximum height of the resized image
	 * @return false|mixed The contents of the resized image, or false on failure
	 */
	function get_resized_image_from_uploaded_file($input_name, $maxwidth, $maxheight) {
		// If our file exists ...
		if (isset($_FILES[$input_name]) && $_FILES[$input_name]['error'] == 0) {
			
			return get_resized_image_from_existing_file($_FILES[$input_name]['tmp_name'], $maxwidth, $maxheight);
			
		}
		
		return false;
	}
	
	/**
	 * Gets the jpeg contents of the resized version of an already uploaded image 
	 * (Returns false if the uploaded file was not an image)
	 *
	 * @param string $input_name The name of the file input field on the submission form
	 * @param int $maxwidth The maximum width of the resized image
	 * @param int $maxheight The maximum height of the resized image
	 * @return false|mixed The contents of the resized image, or false on failure
	 */
	function get_resized_image_from_existing_file($input_name, $maxwidth, $maxheight) {
		
		// Get the size information from the image
		if ($imgsizearray = getimagesize($input_name)) {
		
			// Get the contents of the file
			$filecontents = file_get_contents($input_name);
			
			// Get width and height
			$width = $imgsizearray[0];
			$height = $imgsizearray[1];
			$newwidth = $width;
			$newheight = $height;
			
			if ($width > $maxwidth) {
				$newheight = floor($height * ($maxwidth / $width));
				$newwidth = $maxwidth;
			}
			if ($newheight > $maxheight) {
				$newwidth = floor($newwidth * ($maxheight / $newheight));
				$newheight = $maxheight; 
			}
			
			$accepted_formats = array(
											'image/jpeg' => 'jpeg',
											'image/png' => 'png',
											'image/gif' => 'png'
									);
			
			// If it's a file we can manipulate ...
			if (array_key_exists($imgsizearray['mime'],$accepted_formats)) {

				$function = "imagecreatefrom" . $accepted_formats[$imgsizearray['mime']];
				$newimage = imagecreatetruecolor($newwidth,$newheight);
				if (is_callable($function) && $oldimage = $function($input_name)) {
				
					// Resize and return the image contents!
					imagecopyresampled($newimage, $oldimage, 0,0,0,0,$newwidth,$newheight,$width,$height);
					// imagecopyresized($newimage, $oldimage, 0,0,0,0,$newwidth,$newheight,$width,$height);
					ob_start();
					imagejpeg($newimage, null, 90);
					$jpeg = ob_get_clean();
					return $jpeg;
					
				}
				
			}
			
		}
			
		return false;
	}
	

	/// Variable holding the default datastore
	$DEFAULT_FILE_STORE = NULL;
	
	/**
	 * Return the default filestore.
	 *
	 * @return ElggFilestore
	 */
	function get_default_filestore()
	{
		global $DEFAULT_FILE_STORE;
		
		return $DEFAULT_FILE_STORE;
	}
	
	/**
	 * Set the default filestore for the system.
	 */
	function set_default_filestore(ElggFilestore $filestore)
	{
		global $DEFAULT_FILE_STORE;
		
		$DEFAULT_FILE_STORE = $filestore;
		
		return true;
	}

	/**
	 * Run once and only once.
	 */
	function filestore_run_once()
	{
		// Register a class
		add_subtype("object", "file", "ElggFile");	
	}
	
	/** 
	 * Initialise the file modules. 
	 * Listens to system boot and registers any appropriate file types and classes 
	 */
	function filestore_init()
	{
		global $CONFIG;
		
		// Now register a default filestore
		set_default_filestore(new ElggDiskFilestore($CONFIG->dataroot));
		
		// Now run this stuff, but only once
		run_function_once("filestore_run_once");
	}
	
	// Register a startup event
	register_event_handler('init','system','filestore_init',100);	
?>
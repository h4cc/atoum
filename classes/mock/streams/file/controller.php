<?php

namespace mageekguy\atoum\mock\streams\file;

use
	mageekguy\atoum\exceptions,
	mageekguy\atoum\mock\stream
;

class controller extends stream\controller
{
	protected $exists = true;
	protected $read = false;
	protected $write = false;
	protected $eof = false;
	protected $pointer = 0;
	protected $contents = '';
	protected $stats = array();

	public function __construct($path)
	{
		parent::__construct($path);

		$this->stats = array(
			'dev' => 0,
			'ino' => 0,
			'mode' => 0,
			'nlink' => 0,
			'uid' => getmyuid(),
			'gid' => getmygid(),
			'rdev' => 0,
			'size' => 0,
			'atime' => 507769200,
			'mtime' => 507769200,
			'ctime' => 507769200,
			'blksize' => 0,
			'blocks' => 0
		);

		$this->stats[0] = & $this->stats['dev'];
		$this->stats[1] = & $this->stats['ino'];
		$this->stats[2] = & $this->stats['mode'];
		$this->stats[3] = & $this->stats['nlink'];
		$this->stats[4] = & $this->stats['uid'];
		$this->stats[5] = & $this->stats['gid'];
		$this->stats[6] = & $this->stats['rdev'];
		$this->stats[7] = & $this->stats['size'];
		$this->stats[8] = & $this->stats['atime'];
		$this->stats[9] = & $this->stats['mtime'];
		$this->stats[10] = & $this->stats['ctime'];
		$this->stats[11] = & $this->stats['blksize'];
		$this->stats[12] = & $this->stats['blocks'];

		$this->setMode('644');
	}

	public function __set($method, $value)
	{
		switch ($method = static::mapMethod($method))
		{
			case 'mkdir':
			case 'rmdir':
			case 'dir_closedir':
			case 'dir_opendir':
			case 'dir_readdir':
			case 'dir_rewinddir':
				throw new exceptions\logic\invalidArgument('Unable to override streamWrapper::' . $method . '() for file');

			default:
				return parent::__set($method, $value);
		}
	}

	public function invoke($method, array $arguments = array())
	{
		$method = static::mapMethod($method);

		switch ($method)
		{
			case 'mkdir':
			case 'rmdir':
			case 'dir_closedir':
			case 'dir_opendir':
			case 'dir_readdir':
			case 'dir_rewinddir':
				return false;

			default:
				if ($this->nextCallIsOverloaded($method) === true)
				{
					return parent::invoke($method, $arguments);
				}
				else
				{
					$this->addCall($method, $arguments);

					switch ($method)
					{
						case 'stream_close':
						case 'stream_lock':
							return true;

						case 'stream_open':
							return $this->open($arguments[1], $arguments[2], $arguments[3]);

						case 'stream_stat':
						case 'url_stat':
							return $this->stat();

						case 'stream_metadata':
							return $this->metadata($arguments[1], $arguments[2]);

						case 'stream_tell':
							return $this->tell();

						case 'stream_read':
							return $this->read($arguments[0]);

						case 'stream_write':
							return $this->write($arguments[0]);

						case 'stream_seek':
							return $this->seek($arguments[0], $arguments[1]);

						case 'stream_eof':
							return $this->eof();

						case 'stream_truncate':
							return $this->truncate($arguments[0]);

						case 'rename':
							return $this->setPath($arguments[1]);

						case 'unlink':
							return $this->unlink();

						default:
							return parent::invoke($method, $arguments);
					}
				}
		}
	}

	public function duplicate()
	{
		$controller = parent::duplicate();

		$controller->contents = & $this->contents;
		$controller->stats = & $this->stats;
		$controller->exists = & $this->exists;

		return $controller;
	}

	public function setMode($mode)
	{
		$this->stats['mode'] = 0100000 | octdec($mode);

		return $this;
	}

	public function getMode()
	{
		return (int) sprintf('%03o', $this->stats['mode'] & 07777);
	}

	public function getPointer()
	{
		return $this->pointer;
	}

	public function setContents($contents)
	{
		$this->contents = $contents;
		$this->stats['size'] = ($contents == '' ? 0 : strlen($contents) + 1);

		return true;
	}

	public function getContents()
	{
		return $this->contents;
	}

	public function open($mode, $options, & $openedPath = null)
	{
		$isOpened = false;

		$reportErrors = ($options & STREAM_REPORT_ERRORS) == STREAM_REPORT_ERRORS;

		if (self::checkOpenMode($mode) === false)
		{
			if ($reportErrors === true)
			{
				trigger_error('Operation timed out', E_USER_WARNING);
			}
		}
		else
		{
			$this->setOpenMode($mode);

			switch (true)
			{
				case $this->read === true && $this->write === false:
					$isOpened = $this->checkIfReadable();
					break;

				case $this->read === false && $this->write === true:
					$isOpened = $this->checkIfWritable();
					break;

				default:
					$isOpened = $this->checkIfReadable() && $this->checkIfWritable();
			}

			if ($isOpened === false)
			{
				if ($reportErrors === true)
				{
					trigger_error('Permission denied', E_USER_WARNING);
				}
			}
			else
			{
				switch (self::getRawOpenMode($mode))
				{
					case 'w':
						$this->exists = true;
						$this->truncate(0);
						$this->seek(0);
						break;

					case 'r':
						$isOpened = $this->exists;

						if ($isOpened === true)
						{
							$this->seek(0);
						}
						else if ($reportErrors === true)
						{
							trigger_error('No such file or directory', E_USER_WARNING);
						}
						break;

					case 'c':
						$this->exists = true;
						$this->seek(0);
						break;

					case 'x':
						if ($this->exists === false)
						{
							$this->seek(0);
						}
						else
						{
							$isOpened = false;

							if ($reportErrors === true)
							{
								trigger_error('File exists', E_USER_WARNING);
							}
						}
						break;

					case 'a':
						$this->seek(0, SEEK_END);
						break;
				}
			}
		}

		$openedPath = null;

		if ($isOpened === true && $options & STREAM_USE_PATH)
		{
			$openedPath = $this->getPath();
		}

		return $isOpened;
	}

	public function read($length)
	{
		$data = '';

		$contentsLength = strlen($this->contents);

		if ($this->pointer >= 0 && $this->pointer < $contentsLength)
		{
			$data = substr($this->contents, $this->pointer, $length);
		}

		$this->pointer += $length;

		if ($this->pointer >= $contentsLength)
		{
			$this->eof = true;
			$this->pointer = $contentsLength;
		}

		return $data;
	}

	public function write($data)
	{
		$bytesWrited = 0;

		if ($this->write === true)
		{
			$this->contents .= $data;
			$bytesWrited = strlen($data);
			$this->pointer += $bytesWrited;
			$this->stats['size'] = ($this->contents == '' ? 0 : strlen($this->contents) + 1);
		}

		return $bytesWrited;
	}

	public function truncate($newSize)
	{
		$contents = $this->contents;

		if ($newSize < strlen($this->contents))
		{
			$contents = substr($contents, 0, $newSize);
		}
		else
		{
			$contents = str_pad($contents, $newSize, "\0");
		}

		return $this->setContents($contents);
	}

	public function seek($offset, $whence = SEEK_SET)
	{
		switch ($whence)
		{
			case SEEK_CUR:
				$offset = $this->pointer + $offset;
				break;

			case SEEK_END:
				$offset = strlen($this->contents) + $offset;
				break;
		}

		$this->eof = false;

		if ($this->pointer === $offset)
		{
			return false;
		}
		else
		{
			$this->pointer = $offset;

			return true;
		}
	}

	public function tell()
	{
		return $this->pointer;
	}

	public function eof()
	{
		return $this->eof;
	}

	public function stat()
	{
		return ($this->exists === false ? false : $this->stats);
	}

	public function metadata($option, $value)
	{
		switch ($option)
		{
			case STREAM_META_TOUCH:
				return true;

			case STREAM_META_OWNER_NAME:
				return true;

			case STREAM_META_OWNER:
				return true;

			case STREAM_META_GROUP_NAME:
				return true;

			case STREAM_META_GROUP:
				return true;

			case STREAM_META_ACCESS:
				$this->setMode($value);
				return true;

			default:
				return false;
		}
	}

	public function unlink()
	{
		if ($this->exists === false || $this->checkIfWritable() === false)
		{
			return false;
		}
		else
		{
			$this->exists = false;

			return true;
		}
	}

	public function exists()
	{
		$this->exists = true;

		return $this;
	}

	public function notExists()
	{
		$this->exists = false;

		return $this;
	}

	public function isNotReadable()
	{
		return $this->removePermissions(0444);
	}

	public function isReadable()
	{
		return $this->addPermission(0444);
	}

	public function isNotWritable()
	{
		return $this->removePermissions(0222);
	}

	public function isWritable()
	{
		return $this->addPermission(0222);
	}

	public function isNotExecutable()
	{
		return $this->removePermissions(0111);
	}

	public function isExecutable()
	{
		return $this->addPermission(0111);
	}

	public function contains($contents)
	{
		$this->setContents($contents);
		$this->pointer = 0;
		$this->eof = false;

		return $this;
	}

	public function isEmpty()
	{
		return $this->contains('');
	}

	public function setPath($path)
	{
		parent::setPath($path);

		return true;
	}

	protected function addPermission($permissions)
	{
		$this->stats['mode'] = $this->stats['mode'] | $permissions;

		return $this;
	}

	protected function removePermissions($permissions)
	{
		$this->stats['mode'] = $this->stats['mode'] & ~ $permissions;

		return $this;
	}

	protected function setOpenMode($mode)
	{
		$this->read = false;
		$this->write = false;

		switch (rtrim($mode, 'bt'))
		{
			case 'r':
			case 'x':
				$this->read = true;
				break;

			case 'w':
			case 'a':
			case 'c':
				$this->write = true;
				break;

			case 'r+':
			case 'x+':
			case 'w+':
			case 'a+':
			case 'c+':
				$this->read = $this->write = true;
		}

		return $this;
	}

	protected function checkIfReadable()
	{
		return $this->checkPermission(0400, 0040, 0004);
	}

	protected function checkIfWritable()
	{
		return $this->checkPermission(0200, 0020, 0002);
	}

	protected function checkPermission($user, $group, $other)
	{
		$permissions = $this->stats['mode'] & 07777;

		switch (true)
		{
			case getmyuid() === $this->stats['uid']:
				return ($permissions & $user) > 0;

			case getmygid() === $this->stats['gid']:
				return ($permissions & $group) > 0;

			default:
				return ($permissions & $other) > 0;
		}
	}

	protected static function getRawOpenMode($mode)
	{
		return rtrim($mode, 'bt+');
	}

	protected static function checkOpenMode($mode)
	{
		switch (self::getRawOpenMode($mode))
		{
			case 'r':
			case 'w':
			case 'a':
			case 'x':
			case 'c':
				return true;

			default:
				return false;
		}
	}
}

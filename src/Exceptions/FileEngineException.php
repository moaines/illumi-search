<?php

namespace Moaines\IllumiSearch\Exceptions;

class FileEngineException extends \RuntimeException {}

class IOException extends FileEngineException {}

class CorruptChunkException extends FileEngineException {}

class StatsNotFoundException extends FileEngineException {}

class CacheException extends FileEngineException {}

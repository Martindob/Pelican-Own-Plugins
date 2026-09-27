<?php

namespace Boy132\MinecraftModrinth\Modrinth;

use Exception;

/**
 * The Modrinth metadata file of a server exists but can't be read or trusted right now.
 * Nothing is written while this is the case: writing would replace every entry with one.
 */
class ModrinthMetadataException extends Exception {}

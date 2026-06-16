<?php

declare(strict_types=1);

return [
	'ocs' => [
		// Compress the selected node(s) into a single zip in their parent folder.
		['name' => 'api#compress', 'url' => '/api/v1/compress', 'verb' => 'POST'],
		// Extract one archive into its parent folder.
		['name' => 'api#extract',  'url' => '/api/v1/extract',  'verb' => 'POST'],
	],
];

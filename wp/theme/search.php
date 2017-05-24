<?php
namespace elasticsearch;

class Search extends AbstractArchive
{
    public function facets($wp_query, $args)
	{
		if (!is_search() || !Config::option('enable')) {
			return;
		}

		return $args;
	}
}

$elasticSearch = new Search();

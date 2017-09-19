<?php
namespace elasticsearch;

abstract class AbstractArchive
{
    protected $searched = false;
    protected $attempted = false;
    protected $total = 0;
    protected $scores = array();
    protected $page = 1;
    protected $search = '';
    protected $_ids;
    protected $_blogIds;
    protected $_aggregations;

	public function __construct()
	{
		add_action('pre_get_posts', array(&$this, 'do_search'), 100);
		add_action('the_posts', array(&$this, 'process_search'));
	}

	public function do_search($wp_query)
	{
		if (!$wp_query->is_main_query() || is_admin() || $this->attempted) {
			return;
		}

		$this->attempted = true;

		$args = $this->facets($wp_query, isset($_GET['es']) ? $_GET['es'] : array());

		if ($args === null) {
			return;
		}

		$this->page = isset($wp_query->query_vars['paged']) && $wp_query->query_vars['paged'] > 0 ? $wp_query->query_vars['paged'] - 1 : 0;

		wp_reset_query();

		if (!isset($wp_query->query_vars['posts_per_page'])) {
			$wp_query->query_vars['posts_per_page'] = get_option('posts_per_page');
		}

		//add brackets to make sure the search is seen as a standalone part of the query (relevance is added via AND operator)
		$this->search = '('.$this->_getSearchQuery($wp_query).')';

		if ($filters = $this->getSelectedFilters()) {
		    $querys = array();
		    foreach ($filters as $field => $terms) {
                $querys[] = $field.'_name:("'.implode('" AND "', $terms).'")';
            }

            $this->search = ($this->search?$this->search.' AND ':'').implode(' AND ', $querys);
        }

		$results = Searcher::search($this->search, $this->page, $wp_query->query_vars['posts_per_page'], $args, $this->search ? false : true);

		if ($results == null) {
			return null;
		}

		$this->total = $results['total'];
		$this->_ids = $results['ids'];
		$this->_blogIds = $results['blog_ids'];
		$this->_aggregations = $results['aggregations'];

		$this->searched = true;
	}

	public function process_search($posts)
	{
	    global $wp_query;
		if ($this->searched) {
			$this->searched = false;

			$posts = array();
			//keep track of current blog id to reset at end
            $currentBlogId = get_current_blog_id();
			foreach ($this->_blogIds as $blog_id => $post_ids) {
			    if ( ! count($post_ids)) {
			        continue;
                }

                switch_to_blog($blog_id);

			    foreach ($post_ids as $post_result) {
			        /** @var \WP_Post $post */
                    $post = get_post($post_result['id']);
                    $post->permalink = get_permalink($post_result['id']);
                    if (array_key_exists('highlights', $post_result) && count($post_result['highlights'])) {
                        $fillContent = ! array_key_exists('post_content', $post_result['highlights']);
                        foreach ($post_result['highlights'] as $field => $highlight) {
                            if ($field !== 'post_title' && $fillContent) {
                                $post->post_content = $highlight;
                            } else {
                                $post->{$field} = $highlight;
                            }
                        }
                    }
                    $posts[] = $post;
                }

            }

            //use switch to original blog id since restore_current_blog only works for the last switch
            //when having more than 2 switches (foreach loop above) it will not return to the correct blog
            switch_to_blog($currentBlogId);
            wp_reset_postdata();

			usort($posts, array(&$this, 'sort_posts'));

            //update active query to match correct pagination data
            $wp_query->found_posts = $this->total;
            $wp_query->max_num_pages = ceil($this->total / $wp_query->query_vars['posts_per_page']);
		}

		return $posts;
	}

	public function sort_posts($a, $b)
	{
	    return array_search($b->ID, $this->_ids) > array_search($a->ID, $this->_ids) ? -1 : 1;
	}

    /**
     * @return mixed
     */
    public function getAggregations()
    {
        return $this->_aggregations;
    }

    /**
     * Get aggregations for given post type
     *
     * @param string $postType The post type to get aggregations for
     *
     * @return array}null
     */
    public function getPostTypeAggregations($postType)
    {
        $query = 'post_type:("'.$postType.'")';

        if ($filters = $this->getSelectedFilters()) {
            $querys = array();
            foreach ($filters as $field => $terms) {
                $querys[] = $field.'_name:("'.implode('" AND "', $terms).'")';
            }

            $query = implode(' AND ', $querys) . ($query?' AND '.$query:'');
        }

        $results = Searcher::search($query);

        return $results?$results['aggregations']:null;
    }

    /**
     * @param mixed $aggregations
     */
    public function setAggregations($aggregations)
    {
        $this->_aggregations = $aggregations;
    }

    public function buildUrl($name, $value, $addSearchQuery = true)
    {
        $selectedFilters = $this->getSelectedFilters() ?: array();

        $selectedFilters[$name][] = $value;

        $query = array(
            'q' => $selectedFilters,
        );

        if ($addSearchQuery) {
            $query['s'] = get_search_query();
        }

        return '?'.http_build_query($query);
    }

    public function getBaseSearchUrl()
    {
        $query = array(
            's' => get_search_query(),
        );

        return '?'.http_build_query($query);
    }

    public function getCurrentSearchUrl()
    {
        $selectedFilters = $this->getSelectedFilters() ?: array();

        $query = array(
            's' => get_search_query(),
            'q' => $selectedFilters,
        );

        $blog = get_blog_details();

        return $blog->path.'?'.http_build_query($query);
    }

    public function isSelectedFilter($name, $value)
    {
        if ($selectedFilters = $this->getSelectedFilters()) {
            return array_key_exists($name, $selectedFilters) && in_array($value, $selectedFilters[$name], true);
        }

        return false;
    }

    /**
     * Get the selected filter from GET param
     *
     * @return array
     */
    public function getSelectedFilters()
    {
        if (array_key_exists('q', $_GET)) {
            return $_GET['q'];
        }

        return null;
    }

    public function buildRemoveUrl($name, $value, $addSearchQuery = true) {
        $selectedFilters = $this->getSelectedFilters();

        if (array_key_exists($name, $selectedFilters) && ($idx = array_search($value, $selectedFilters[$name], true)) !== false) {
            unset($selectedFilters[$name][$idx]);
        }

        $query = array(
            'q' => $selectedFilters,
        );

        if ($addSearchQuery) {
            $query['s'] = get_search_query();
        }

        return '?'.http_build_query($query);
    }

	abstract function facets($wp_query, $existing);

    /**
     * @param $wp_query
     *
     * @return string
     */
    private function _getSearchQuery($wp_query)
    {
        return isset($wp_query->query_vars['s']) ? str_replace(array('\"'), array('"'), urldecode($wp_query->query_vars['s'])) : '';
    }
}

?>

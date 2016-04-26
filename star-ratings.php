<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;
use RocketTheme\Toolbox\File\File;
use Symfony\Component\Yaml\Yaml;

/**
 * Class StarRatingsPlugin
 * @package Grav\Plugin
 */
class StarRatingsPlugin extends Plugin
{
    protected $callback;
    protected $total_stars;
    protected $only_full_stars;

    protected $vote_data;

    protected $cache_id;

    /**
     * @return array
     *
     * The getSubscribedEvents() gives the core a list of events
     *     that the plugin wants to listen to. The key of each
     *     array section is the event that the plugin listens to
     *     and the value (in the form of an array) contains the
     *     callable (or function) as well as the priority. The
     *     higher the number the higher the priority.
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            'onPagesInitialized'   => ['onPagesInitialized', 0],
        ];
    }


    public function onPagesInitialized()
    {
        $uri = $this->grav['uri'];
        $cache = $this->grav['cache'];

        $this->callback = $this->config->get('plugins.star-ratings.callback');
        $this->total_stars = $this->config->get('plugins.star-ratings.total_stars');
        $this->only_full_stars = $this->config->get('plugins.star-ratings.only_full_stars');

        $this->cache_id = md5('vote-data'.$cache->getKey());

        if ($this->callback != $uri->path()) {
            return;
        }
        
        $this->addVote();

        echo json_encode(['status' => 'success']);
        exit();
    }

    public function addVote()
    {
        $star_rating = filter_input(INPUT_POST, 'rating', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        $id          = filter_input(INPUT_POST, 'id', FILTER_SANITIZE_STRING);

        // sanity checks for star ratings
        if ($star_rating < 0) {
            $star_rating = 0;
        } elseif ($star_rating > $this->total_stars ) {
            $star_rating = $this->total_stars;
        }

        // get an int if you pass a float and you shouldn't be
        if (is_float($star_rating) && $this->only_full_stars) {
            $star_rating = ceil($star_rating);
        }

        $vote_data = $this->getVoteData();

        if (array_key_exists($id, $vote_data)) {
            $rating = $vote_data[$id];
            $rating['count']++;
            array_push($rating['votes'], $star_rating);
            $rating['score'] = array_sum($rating['votes']) / $rating['count'];

        } else {
            $rating['count'] = 1;
            $rating['votes'] = [$star_rating];
            $rating['score'] = $star_rating;
        }

        $this->saveVoteData($id, $rating);
    }

    /**
     * Initialize the plugin
     */
    public function onPluginsInitialized()
    {
        // Don't proceed if we are in the admin plugin
        if ($this->isAdmin()) {
            return;
        }

        // Enable the main event we are interested in
        $this->enable([
            'onTwigInitialized' => ['onTwigInitialized', 0],
            'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
        ]);

        $this->getVoteData();
    }

    public function onTwigInitialized()
    {
        $this->grav['twig']->twig()->addFunction(
            new \Twig_SimpleFunction('stars', [$this, 'generateStars'])
        );
    }

    public function onTwigSiteVariables()
    {
        if ($this->config->get('plugins.star-ratings.built_in_css')) {
            $this->grav['assets']
                ->addCss('plugin://star-ratings/assets/star-ratings.css');
        }

        $callback_url = $this->grav['base_url'] . $this->config->get('plugins.star-ratings.callback') . '.json';
        $total_stars = $this->config->get('plugins.star-ratings.total_stars');

        $inline_js = "$(function() {
                        $('.star-rating-container').starRating({
                            starSize: 25,
                            totalStars: ".$total_stars.",
                            disableAfterRate: false,
                            initialRating: $('.star-rating-container').data('stars'),
                            callback: function(currentRating, \$el) {
                                var id = \$el.closest('.star-rating-container').data('id');
                                $.post('".$callback_url."', { id: id, rating: currentRating })
                                 .done(function() {
                                    console.log('success');
                                 })
                                 .fail(function() {
                                    console.log('fail');
                                 });
                            }
                        });
                    });";

        $this->grav['assets']
            ->add('jquery', 101)
            ->addJs('plugin://star-ratings/assets/jquery.star-rating-svg.min.js')
            ->addJs('plugin://star-ratings/assets/star-ratings.js')
            ->addInlineJs($inline_js);


    }

    public function generateStars($id=null, $num_stars=5, $star_width=16)
    {
        if ($id === null) {
            return '<i>ERROR: no id provided to <code>stars()</code> twig function</i>';
        }
        return '<div class="star-rating-container" data-id="'.$id.'" data-stars="'.$this->getStars($id).'"></div>';
    }

    private function getVoteData()
    {
        if (empty($this->vote_data)) {
            $cache = $this->grav['cache'];
            $vote_data = $cache->fetch($this->cache_id);

            if ($vote_data === false) {
                $path = $this->grav['locator']->findResource('user://data', true) . '/star-ratings/star-ratings.yaml';
                $fileInstance = File::instance($path);

                if (!$fileInstance->content()) {
                    $vote_data = [];
                } else {
                    $vote_data = Yaml::parse($fileInstance->content());
                }
                // store data in plugin
                $this->vote_data = $vote_data;

                // store data in cache
                $cache->save($this->cache_id, $this->vote_data);
            }
        }
        return $this->vote_data;
    }

    private function saveVoteData($id = null, $data = null)
    {
        if ($id != null && $data !=null) {
            $this->vote_data[$id] = $data;
        }

        // update data in cache
        $this->grav['cache']->save($this->cache_id, $this->vote_data);

        // save in file
        $path = $this->grav['locator']->findResource('user://data', true) . '/star-ratings/star-ratings.yaml';
        $fileInstance = File::instance($path);
        $yaml = Yaml::dump($this->vote_data);
        $fileInstance->content($yaml);
        $fileInstance->save();
    }

    private function getStars($id)
    {
        $vote_data = $this->getVoteData();
        if (array_key_exists($id, $vote_data)) {
            return $vote_data[$id]['score'];
        } else {
            return 0;
        }
    }

}

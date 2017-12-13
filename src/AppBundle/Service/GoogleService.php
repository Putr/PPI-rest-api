<?php
namespace AppBundle\Service;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Validator\Constraints\DateTime;

use AppBundle\Command\ScraperCommand;
use AppBundle\Entity\SocialMedia;
use AppBundle\Entity\Statistic;

class GoogleService
{
    private   $container;
    protected $connect;
    protected $db;
    protected $images;

    protected $partyCode;
    protected $googleId;
    protected $yt;

    public function __construct(Container $container) {
        $this->container = $container;
        $this->connect   = $this->container->get('ConnectionService');
        $this->db        = $this->container->get('DatabaseService');
        $this->images    = $this->container->get('ImageService');
        @set_exception_handler(array($db, 'exception_handler'));
    }


    /**
     * Queries Youtube for stats and videos
     * @param  string $partyCode
     * @param  string $googleId
     * @return array
     */
    public function getYoutubeData($partyCode, $googleId) {
        $this->partyCode = $partyCode;
        $this->googleId  = $googleId;
        $this->yt        = $this->connect->getNewGoogle($googleId, true);

        echo "     + Info and stats... ";
        if (!$this->yt) {
            echo "not found\n";
            return false;
        }

        $data = $this->yt->getChannelByName($googleId);
        if (empty($data) || empty($data->statistics)) {
            echo "not found\n";
            return false;
        }

        $out['stats'] = $this->getYtStats($data->statistics);
        echo "ok\n";

        $playlist = $data->contentDetails->relatedPlaylists->uploads;
        $videos   = $this->yt->getPlaylistItemsByPlaylistId($playlist);
        $vidCount = 0;

        echo "     + Videos... ";
        if (empty($videos)) {
            echo "not found\n";
            return $out;
        }

        foreach ($videos as $key => $vid) {
            $this->getVideoDetails($vid);
            $vidCount++;
        }
        echo $vidCount . " found and processed\n";
        $out['videos'] = $vidCount;

        return $out;
    }


    /**
     * Retrieves channel statistics
     * @param object $stats
     * @return null
     */
    public function getYtStats($stats) {
        // these stats are strings, so we need to cast them to int to save them to db
        $array = [];

        if (!empty($stats->subscriberCount)) {
            $this->db->addStatistic(
                $this->partyCode,
                Statistic::TYPE_YOUTUBE,
                Statistic::SUBTYPE_SUBSCRIBERS,
                (int)$stats->subscriberCount
            );
            $array['subCount'] = true;
        }

        if (!empty($stats->subscriberCount)) {
            $this->db->addStatistic(
                $this->partyCode,
                Statistic::TYPE_YOUTUBE,
                Statistic::SUBTYPE_VIEWS,
                (int)$stats->viewCount
            );
            $array['viewCount'] = true;
        }

        if (!empty($stats->subscriberCount)) {
            $this->db->addStatistic(
                $this->partyCode,
                Statistic::TYPE_YOUTUBE,
                Statistic::SUBTYPE_VIDEOS,
                (int)$stats->videoCount
            );
            $array['vidCount'] = true;
        }

        return $array;
    }


    /**
     * Retrieves details of a video
     * @param  array $vid
     * @return array
     */
    public function getVideoDetails($vid) {
        $vidId   = $vid->snippet->resourceId->videoId;
        $vidTime = \DateTime::createFromFormat('Y-m-d\TH:i:s.u\Z', $vid->snippet->publishedAt);
        // original ISO 8601, e.g. '2015-04-30T21:45:59.000Z'

        $imgSrc  = $vid->snippet->thumbnails->medium->url;
        // deafult=120x90, medium=320x180, high=480x360, standard=640x480, maxres=1280x720
        $img     = $this->images->saveImage('yt', $this->partyCode, $imgSrc, $vidId);

        $vidInfo  = $this->yt->getVideoInfo($vidId);
        $vidLikes = isset($vidInfo->statistics->likeCount)    ? $vidInfo->statistics->likeCount    : null;
        $vidViews = isset($vidInfo->statistics->viewCount)    ? $vidInfo->statistics->viewCount    : null;
        $vidComms = isset($vidInfo->statistics->commentCount) ? $vidInfo->statistics->commentCount : null;

        $data = [
            'id'          => $vidId,
            'posted'      => $vidTime->format('Y-m-d H:i:s'), // string
            'text'        => $vid->snippet->title,
            'description' => $vid->snippet->description,
            'image'       => $img,
            'img_source'  => $imgSrc,
            'url'         => 'https://www.youtube.com/watch?v=' . $vidId,
            'views'       => $vidViews,
            'likes'       => $vidLikes,
            'comments'    => $vidComms
            ];

        $this->db->addSocial(
            $this->partyCode,
            SocialMedia::TYPE_YOUTUBE,
            SocialMedia::SUBTYPE_VIDEO,
            $vidId,
            $vidTime, // DateTime
            $vid->snippet->title,
            $img,
            $vidLikes,
            $data
        );
    }


    /**
     * Queries Google+ for followers
     * @param  string $googleId
     * @return int
     */
    public function getGooglePlusData($partyCode, $googleId) {
        $data = $this->connect->getNewGoogle($googleId);

        if (empty($data) || !isset($data->circledByCount)) {
            return false;
        }

        $this->db->addStatistic(
            $partyCode,
            Statistic::TYPE_GOOGLEPLUS,
            Statistic::SUBTYPE_FOLLOWERS,
            $data->circledByCount
        );

        return true;
    }

}
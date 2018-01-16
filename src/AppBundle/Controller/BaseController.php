<?php

namespace AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use AppBundle\Entity\Statistic as Stat;

class BaseController extends Controller
{
    /**
     * Queries for a single party
     * @param  string $code
     * @return Party
     */
    public function getOneParty($code) {
        $party = $this->getDoctrine()
            ->getRepository('AppBundle:Party')
            ->findOneByCode($code);

        return $party;
    }


    /**
     * Queries for all parties
     * @param  bool   $showDefunct      <optional>
     * @param  string $membershipFilter <optional>
     * @param  string $orderBy          <optional>
     * @return array
     */
    public function getAllParties($showDefunct = false, $membershipFilter = null, $orderBy = 'code') {
        $parties = $this->getDoctrine()
            ->getRepository('AppBundle:Party');

        $query = $parties->createQueryBuilder('qb')
            ->select('p')->from('AppBundle:Party', 'p');

        if (!is_null($membershipFilter)) { // filter by intOrg membership, 'ppi', 'ppeu' etc.
            $query->join('p.intMemberships', 'm')
                ->innerJoin('m.intOrg', 'o')
                ->where(sprintf("o.code = '%s'", $membershipFilter));
        }

        if (!is_null($showDefunct)) { // true = show only defunct, false = only non-defunct, null = show all
            $query->andwhere(sprintf("p.defunct = '%s'", $showDefunct));
        }

        switch ($orderBy) {
            case 'name':
                $query->orderBy('p.name', 'ASC');
                break;
            case 'country':
                $query->orderBy('p.countryName', 'ASC');
                break;
            default: // case 'code' or null
                $query->orderBy('p.code', 'ASC');
        }

        $parties = $query->getQuery()->getResult();

        $allData = array();
        foreach ($parties as $party) {
            $allData[strtolower($party->getCode())] = $party;
        }

        return $allData;
    }


    /**
    * Queries for a single type of social media post
    * @param  string $code
    * @param  string $type
    * @param  string $subType
    * @return SocialMedia
    */
    public function getOneSocial($code, $type, $subType) {
        $socialMedia = $this->getDoctrine()
            ->getRepository('AppBundle:SocialMedia')
            ->findBy(['code' => $code, 'type' => $type, 'subType' => $subType]);

        if (!$socialMedia) {
            return false;
        }

        return $socialMedia;
    }


    /**
    * Queries for all social media posts
    * @param  string $code    <optional>
    * @param  string $type    <optional>
    * @param  string $subType <optional>
    * @param  string $fields  <optional>
    * @param  string $orderBy <optional>
    * @param  int    $limit   <optional>
    * @param  int    $offset  <optional>
    * @return array
    */
    public function getAllSocial($code = null, $type = null, $subType = null, $fields = null, $orderBy = null, $direction = null, $limit = 100, $offset = 0) {
        $social = $this->getDoctrine()
            ->getRepository('AppBundle:SocialMedia');

        $terms   = [];
        $orderBy = is_null($orderBy) ? 'postTime' : $orderBy;

        if (!$direction) {
            $direction = ($orderBy == 'code') ? 'ASC' : 'DESC';
        }

        if ($code) {
            $terms['code'] = $code;
        }
        if ($type) {
            $terms['type'] = $type;
        }
        if ($subType) {
            $terms['subType'] = $subType;
        }

        $socialMedia = $social->findBy($terms, [$orderBy => $direction], $limit, $offset);

        if ($fields) {
            $socialMedia = $this->getSelectSocial($socialMedia, $fields);
        }

        return $socialMedia;
    }


    /**
    * Builds an array of select fields to return to the API
    * @param  object $socialMedia
    * @param  string $fields
    * @return array
    */
    public function getSelectSocial($socialMedia, $fields) {
        $fields = str_replace(' ', '', $fields);
        $terms  = explode(',', $fields);

        foreach ($socialMedia as $social) {
            $data = $social->getPostData();
            $temp = [
                'code'     => $social->getCode(),
                'type'     => $social->getType(),
                'sub_type' => $social->getSubType(),
                'post_id'  => $data['id'],
                ];

            foreach ($terms as $field) {

                if ($field == 'time') {
                    if ($temp['sub_type'] != 'E') {
                        $temp['post_'.$field] = isset($data['posted']) ? $data['posted'] : null;

                    } else $temp['post_'.$field] = isset($data['start_time']) ? $data['start_time'] : null;

                } else if ($field == 'shares' && $temp['type'] == 'tw') {
                    $temp['post_'.$field] = isset($data['retweets']) ? $data['retweets'] : null;

                } else if ($field == 'engagement') {
                    $temp['post_'.$field] = $this->getPostEngagement($social);

                } else if ($field == 'reach') {
                    $temp['post_'.$field] = $this->getPostReach($social);

                } else {
                    $temp['post_'.$field] = isset($data[$field]) ? $data[$field] : null;
                }
            }

            $out[] = $temp;
        }

        return $out;
    }


    /**
     * Queries for a single statistic
     * @param  string $code    Party Code
     * @param  string $type
     * @param  string $subType <optional>
     * @return Statistic
     */
    public function getStat($code, $type, $subType) {
        $stat = $this->getDoctrine()
            ->getRepository('AppBundle:Statistic')
            ->findOneBy([
                'code'    => strtolower($code),
                'type'    => $type,
                'subType' => $subType
            ],
            [
                'timestamp' => 'DESC'
            ]
        );

        if (!$stat) {
            return '?';
        }

        return $stat->getValue();
    }


    /**
     * Queries for a single meta value
     * @param  string $code
     * @param  string $type
     * @return Metadata
     */
    public function getMeta($code, $type) {
        $meta = $this->getDoctrine()
            ->getRepository('AppBundle:Metadata')
            ->findOneBy([
                'code' => strtolower($code),
                'type' => $type
            ]);

        if(!$meta) {
            return false;
        }

        return $meta->getValue();
    }


    /**
     * Returns the total engagement score of a post
     * @param  object $item
     * @return int
     */
    public function getPostEngagement($item) {
        $data     = $item->getPostData();
        // var_dump($item); die;
        $likes     = isset($data['likes'])     ? $data['likes']     : 0;
        $reactions = isset($data['reactions']) ? $data['reactions'] : 0;
        $shares    = isset($data['shares'])    ? $data['shares']    : 0;
        $retweets  = isset($data['retweets'])  ? $data['retweets']  : 0;
        $comments  = isset($data['comments'])  ? $data['comments']  : 0;

        switch ($item->getType()) {
            case 'fb':
                return $reactions + $shares + $comments;
            case 'tw':
                return $likes + $retweets + $comments;
            default:
                return $likes + $shares + $comments;
        }
    }


    /**
     * Returns the value of a post's engagement per total audience
     * (i.e. page likes, followers, subscribers, etc.)
     * @param  object $item
     * @return float
     */
    public function getPostReach($item) {
        switch ($item->getType()) {
            case 'fb':
                $statType = Stat::TYPE_FACEBOOK;
                $subType  = Stat::SUBTYPE_LIKES;
                break;
            case 'tw':
                $statType = Stat::TYPE_TWITTER;
                $subType  = Stat::SUBTYPE_FOLLOWERS;
                break;
            case 'yt':
                $statType = Stat::TYPE_YOUTUBE;
                $subType  = Stat::SUBTYPE_SUBSCRIBERS;
                break;
            case 'g+':
                $statType = Stat::TYPE_GOOGLEPLUS;
                $subType  = Stat::SUBTYPE_FOLLOWERS;
                break;
            }

        $engagement = $this->getPostEngagement($item);
        $totalReach = $this->getStat($item->getCode(), $statType, $subType);

        return $engagement / $totalReach;
    }

}

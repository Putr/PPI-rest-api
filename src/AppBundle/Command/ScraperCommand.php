<?php
namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use Pirates\PapiInfo\Compile;

use AppBundle\Entity\Metadata;
use AppBundle\Entity\Statistic;
use AppBundle\Entity\SocialMedia;
use AppBundle\Extensions\ScraperServices;
use AppBundle\Extensions\FacebookService;


class ScraperCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('papi:scraper')
            ->setDescription('Scrapes FB, TW and G+ data. Should be run once per day.')
            ->addOption('party',  'p', InputOption::VALUE_OPTIONAL, 'Choose a single party to scrape, by code (i.e. ppse, ppsi)')
            ->addOption('site',   'w', InputOption::VALUE_OPTIONAL, 'Choose a single website to scrape (fb, tw, g+ or yt)')
            ->addOption('data',   'd', InputOption::VALUE_OPTIONAL, 'Choose a single data type to scrape, fb only (info, posts, images, events)')
            ->addOption('resume', 'r', InputOption::VALUE_OPTIONAL, 'Choose a point to resume scraping, by party code (e.g. if previously interrupted)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $who   = $input->getOption('party');  // if null, get all
        $where = $input->getOption('site');   // if null, get all
        $what  = $input->getOption('data');   // if null, get all
        $when  = $input->getOption('resume'); // if null, get all
        switch ($what) {
            case 'photos':
            case 'pictures':
                $what = 'images';
                break;
            case 'text':
                $what = 'posts';
                break;
            case 'data':
            case 'basic':
                $what = 'info';
                break;
        }

        $this->container = $this->getContainer();
        $this->em = $this->container->get('doctrine')->getManager();

        $this->output = $output;
        $this->logger = $this->getContainer()->get('logger');
        $logger = $this->logger;

        $scraperService  = $this->container->get('ScraperServices');
        $facebookService = $this->container->get('FacebookService');
        $twitterService  = $this->container->get('TwitterService');
        $googleService   = $this->container->get('GoogleService');

        $output->writeln("##### Starting scraper #####");
        $startTime = new \DateTime('now');

        if (empty($who)) {
            $output->writeln("# Getting all parties");
            $parties = $scraperService->getAllParties();
            $output->writeln("Done");
        } else {
            $output->writeln("# Getting one party (". $who .")");
            $parties = $scraperService->getOneParty($who);
            $output->writeln("Done");
        }

        // Verify argument search terms
        if ($where != null && $where != 'yt' && $where != 'g+' && $where != 'tw' && $where != 'fb') {
            $output->writeln("   + ERROR - Search term \"". $where ."\" not recognised");
            $output->writeln("# Process halted");
            die;
        }
        if ($what != null && $where != 'fb') {
            $output->writeln("   + ERROR - Search term \"". $what ."\" only valid when limited to Facebook");
            $output->writeln("# Process halted");
            die;
        }

        foreach ($parties as $code => $party) {

            if (!empty($when) && $code < $when) {
                echo " - ".$code." skipped...\n";
            } else {

                $output->writeln(" - Processing " . $code);
                $sn = $party->getSocialNetworks();
                $midTime = new \DateTime('now');

                if (empty($sn)) {
                    continue;
                }

                //
                // FACEBOOK
                //
                if ($where == null || $where == 'fb') {
                    if (!empty($sn['facebook']) && !empty($sn['facebook']['username'])) {
                        $output->writeln("   + Starting Facebook import");
                        $fd = $facebookService->getFBData($sn['facebook']['username'], $what, $code);

                        if ($what == null || $what == 'info') {
                            if ($fd == false || empty($fd['likes'])) {
                                $output->writeln("     + ERROR while retrieving FB data");
                                $sn['errors'][] = [$code => 'fb'];
                            } else {
                                $output->writeln("   + Facebook data retrieved");

                                $scraperService->addMeta(
                                    $code,
                                    Metadata::TYPE_FACEBOOK_INFO,
                                    json_encode($fd['info'])
                                );
                                $output->writeln("     + General info added");

                                $scraperService->addStatistic(
                                    $code,
                                    Statistic::TYPE_FACEBOOK,
                                    Statistic::SUBTYPE_LIKES,
                                    $fd['likes']
                                );
                                $output->writeln("     + 'Like' count added");

                                $scraperService->addStatistic(
                                    $code,
                                    Statistic::TYPE_FACEBOOK,
                                    Statistic::SUBTYPE_TALKING,
                                    $fd['talking']
                                );
                                $output->writeln("     + 'Talking about' count added");

                                $scraperService->addStatistic(
                                    $code,
                                    Statistic::TYPE_FACEBOOK,
                                    Statistic::SUBTYPE_POSTS,
                                    $fd['postCount']
                                );
                                $output->writeln("     + Post count added");

                                $scraperService->addStatistic(
                                    $code,
                                    Statistic::TYPE_FACEBOOK,
                                    Statistic::SUBTYPE_IMAGES,
                                    $fd['photoCount']
                                );
                                $output->writeln("     + Photo count added");

                                $scraperService->addStatistic(
                                    $code,
                                    Statistic::TYPE_FACEBOOK,
                                    Statistic::SUBTYPE_EVENTS,
                                    $fd['eventCount']
                                );
                                $output->writeln("     + Event count added");
                                $output->writeln("   + All statistics added");

                                $cover = $facebookService->getFacebookCover($code, $fd['cover']);
                                $output->writeln("     + Cover retrieved");

                                $scraperService->addMeta(
                                    $code,
                                    Metadata::TYPE_FACEBOOK_COVER,
                                    $cover
                                );
                                $output->writeln("     + Cover added");
                            }
                        }

                        if ($what == null || $what == 'posts') {
                            if (empty($fd['posts'])) {
                                $output->writeln("     + Text post data not found");
                                $sn['errors'][] = [$code => 'fb posts not found'];
                            } else {
                                foreach ($fd['posts'] as $key => $post) {
                                    $scraperService->addSocial(
                                        $code,
                                        SocialMedia::TYPE_FACEBOOK,
                                        SocialMedia::SUBTYPE_TEXT,
                                        $post['postId'],
                                        $post['postTime'],
                                        $post['postText'],
                                        $post['postImage'],
                                        $post['postLikes'],
                                        json_encode($post['postData'])
                                    );
                                }
                                $output->writeln("     + Text posts added");
                            }
                        }

                        if ($what == null || $what == 'images') {
                            if (empty($fd['photos'])) {
                                $output->writeln("     + Photo data not found");
                                $sn['errors'][] = [$code => 'fb photos not found'];
                            } else {
                                foreach ($fd['photos'] as $key => $image) {
                                    $scraperService->addSocial(
                                        $code,
                                        SocialMedia::TYPE_FACEBOOK,
                                        SocialMedia::SUBTYPE_IMAGE,
                                        $image['postId'],
                                        $image['postTime'],
                                        $image['postText'],
                                        $image['postImage'],
                                        $image['postLikes'],
                                        json_encode($image['postData'])
                                    );
                                }
                                $output->writeln("     + Photos added");
                            }
                        }

                        if ($what == null || $what == 'events') {
                            if (empty($fd['events'])) {
                                $output->writeln("     + Event data not found");
                                $sn['errors'][] = [$code => 'fb events not found'];
                            } else {
                                foreach ($fd['events'] as $key => $event) {
                                    $scraperService->addSocial(
                                        $code,
                                        SocialMedia::TYPE_FACEBOOK,
                                        SocialMedia::SUBTYPE_EVENT,
                                        $event['postId'],
                                        $event['postTime'],
                                        $event['postText'],
                                        $event['postImage'],
                                        $event['postLikes'],
                                        json_encode($event['postData'])
                                    );
                                }
                                $output->writeln("     + Events added");
                            }
                        }
                    $output->writeln("   + All social media added");
                    }
                }

                //
                // TWITTER
                //
                if ($where == null || $where == 'tw') {
                    if (!empty($sn['twitter']) && !empty($sn['twitter']['username'])) {
                        $output->writeln("   + Starting Twitter import");
                        $td = $twitterService->getTwitterData($sn['twitter']['username'], $code);

                        if ($td == false || empty($td['followers']) || empty($td['tweets'])) {
                            $output->writeln("     + ERROR while retrieving TW data");
                            $sn['errors'][] = [$code => 'tw'];
                        } else {
                            $output->writeln("   + Twitter data retrieved");

                            $scraperService->addMeta(
                                $code,
                                Metadata::TYPE_TWITTER_INFO,
                                json_encode($td['description'])
                            );
                            $output->writeln("     + General info added");

                            $scraperService->addStatistic(
                                $code,
                                Statistic::TYPE_TWITTER,
                                Statistic::SUBTYPE_LIKES,
                                $td['likes']
                            );
                            $output->writeln("     + 'Like' count added");

                            $scraperService->addStatistic(
                                $code,
                                Statistic::TYPE_TWITTER,
                                Statistic::SUBTYPE_FOLLOWERS,
                                $td['followers']
                            );
                            $output->writeln("     + Follower count added");

                            $scraperService->addStatistic(
                                $code,
                                Statistic::TYPE_TWITTER,
                                Statistic::SUBTYPE_FOLLOWING,
                                $td['following']
                            );
                            $output->writeln("     + Following count added");

                            $scraperService->addStatistic(
                                $code,
                                Statistic::TYPE_TWITTER,
                                Statistic::SUBTYPE_POSTS,
                                $td['tweets']
                            );
                            $output->writeln("     + Tweet count added");
                            $output->writeln("   + All statistics added");

                            if (empty($td['posts'])) {
                                $output->writeln("     + Tweet data not found");
                                $sn['errors'][] = [$code => 'tw posts'];
                            } else {
                                foreach ($td['posts'] as $key => $post) {
                                    $scraperService->addSocial(
                                        $code,
                                        SocialMedia::TYPE_TWITTER,
                                        SocialMedia::SUBTYPE_TEXT,
                                        $post['postId'],
                                        $post['postTime'],
                                        $post['postText'],
                                        $post['postImage'],
                                        $post['postLikes'],
                                        json_encode($post['postData'])
                                    );
                                }
                                $output->writeln("     + Tweets added");
                            }

                            if (empty($td['images'])) {
                                $output->writeln("     + Image data not found");
                                $sn['errors'][] = [$code => 'tw images'];
                            } else {
                                foreach ($td['images'] as $key => $image) {
                                    $scraperService->addSocial(
                                        $code,
                                        SocialMedia::TYPE_TWITTER,
                                        SocialMedia::SUBTYPE_IMAGE,
                                        $image['postId'],
                                        $image['postTime'],
                                        $image['postText'],
                                        $image['postImage'],
                                        $image['postLikes'],
                                        json_encode($image['postData'])
                                    );
                                }
                                $output->writeln("     + Images added");
                            }
                            $output->writeln("   + All social media added");
                        }
                    }
                }

                //
                // Google+
                //
                if ($where == null || $where == 'g+') {
                    if (!empty($sn['googlePlus'])) {
                        $output->writeln("   + Starting GooglePlus import");
                        $gd = $googleService->getGooglePlusData($sn['googlePlus']);

                        if ($gd == false || empty($gd)) {
                            $output->writeln("     + ERROR while retrieving G+ data");
                            $sn['errors'][] = [$code => 'g+'];
                        } else {
                            $output->writeln("   + GooglePlus data retrieved");

                            $scraperService->addStatistic(
                                $code,
                                Statistic::TYPE_GOOGLEPLUS,
                                Statistic::SUBTYPE_FOLLOWERS,
                                $gd
                            );
                            $output->writeln("   + Follower count added");
                        }
                    }
                }

                //
                // Youtube
                //
                if ($where == null || $where == 'yt') {
                    if (!empty($sn['youtube'])) {
                        $output->writeln("   + Starting Youtube import");
                        $yd = $googleService->getYoutubeData($sn['youtube'], $code);

                        if ($yd == false || empty($yd)) {
                            $output->writeln("     + ERROR while retrieving Youtube data");
                            $sn['errors'][] = [$code => 'yt'];
                        } else {
                            $output->writeln("   + Youtube data retrieved");

                            $scraperService->addStatistic(
                                $code,
                                Statistic::TYPE_YOUTUBE,
                                Statistic::SUBTYPE_SUBSCRIBERS,
                                $yd['stats']['subscriberCount']
                            );
                            $output->writeln("     + Subscriber count added");

                            $scraperService->addStatistic(
                                $code,
                                Statistic::TYPE_YOUTUBE,
                                Statistic::SUBTYPE_VIEWS,
                                $yd['stats']['viewCount']
                            );
                            $output->writeln("     + View count added");

                            $scraperService->addStatistic(
                                $code,
                                Statistic::TYPE_YOUTUBE,
                                Statistic::SUBTYPE_VIDEOS,
                                $yd['stats']['videoCount']
                            );
                            $output->writeln("     + Video count added");
                            $output->writeln("   + All statistics added");

                            if (empty($yd['videos'])) {
                                $output->writeln("     + Video data not found");
                                $sn['errors'][] = [$code => 'yt'];
                            } else {
                                foreach ($yd['videos'] as $key => $video) {
                                    $scraperService->addSocial(
                                        $code,
                                        SocialMedia::TYPE_YOUTUBE,
                                        SocialMedia::SUBTYPE_VIDEO,
                                        $video['postId'],
                                        $video['postTime'],
                                        $video['postText'],
                                        $video['postImage'],
                                        $video['postLikes'],
                                        json_encode($video['postData'])
                                    );
                                }
                                $output->writeln("     + Videos added");
                            }
                            $output->writeln("   + All social media added");
                        }
                    }
                }

                $output->writeln(" # Saving to DB");
                $this->em->flush();

                $endTime = new \DateTime('now');
                $midDiff = $midTime->diff($endTime);
                $output->writeln("   + Done in ".$midDiff->format('%H:%I:%S'));
            }
        }

        $fullDiff = $startTime->diff($endTime);
        $output->writeln("# Completed in ".$fullDiff->format('%H:%I:%S'));

        if (!empty($sn['errors'])) {
            $output->writeln("# Errors:");
            var_dump($sn['errors']);
        }
        
    }

}
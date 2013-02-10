<?php
require_once ChibiConfig::getInstance()->chibi->runtime->rootFolder . '/src/controllers/abstract.php';
require_once ChibiConfig::getInstance()->chibi->runtime->rootFolder . '/src/models/user/listservice.php';

class StatsController extends AbstractController {
	public function init() {
		parent::init();
		$this->sessionHelper->close();

		//no user specified
		$userNames = $this->inputHelper->getStringSafe('u');
		if (empty($userNames)) {
			throw new Exception('User name not specified.');
		}
		//user is not an array
		$userNames = explode(',', $userNames);
		foreach ($userNames as $userName) {
			if (empty($userName)) {
				throw new Exception('User name cannot be empty.');
			}
		}
		//make users unique
		$userNames = array_unique($userNames);
		$this->view->userNames = $userNames;

		//load anime-manga switch
		$am = $this->inputHelper->get('am');
		if ($am != AMModel::TYPE_MANGA) {
			$am = AMModel::TYPE_ANIME;
		}
		$this->view->am = $am;

		if (count($this->view->userNames) > 2) {
			throw new Exception('Sorry. We haven\'t implemented this.');
		}

		//try to load requested users
		$anons = [];
		$modelUsers = new UserModel();
		$this->view->users = [];
		foreach ($this->view->userNames as $userName) {
			try {
				$userEntry = $modelUsers->get($userName, AbstractModel::CACHE_POLICY_FORCE_CACHE);
			} catch (InvalidEntryException $e) {
				$this->sessionHelper->restore();
				$_SESSION['wrong-user'] = $userName;
				$this->forward($this->mgHelper->constructUrl('index', 'wrong-user'));
				return;
			} catch (DownloadException $e) {
				$this->forward($this->mgHelper->constructUrl('index', 'net-down'));
				return;
			}
			if ($userEntry->getUserData()->isBlocked()) {
				$this->sessionHelper->restore();
				$_SESSION['wrong-user'] = $userName;
				$this->forward($this->mgHelper->constructUrl('index', 'blocked-user'));
			}
			$this->view->users []= $userEntry;
		}

		//set meta
		$titles = [
			'profile' => ['{nick}&rsquo;s profile', '{nicks_amp}'],
			'list' => ['{nick} - list ({am})', '{nicks_amp} - lists ({am})'],
			'rati' => ['{nick} - rating stats ({am})', '{nicks_amp} - rating stats ({am})'],
			'acti' => ['{nick} - activity ({am})', '{nicks_amp} - activity ({am})'],
			'achi' => ['{nick} - achievements ({am})', '{nicks_amp} - achievements ({am})'],
			'sug' => ['{nick} - suggestions ({am})', '{nicks_amp} - suggestions ({am})'],
			'favs' => ['{nick} - favorites ({am})', '{nicks_amp} - favorites ({am})'],
			'misc' => ['{nick} - misc stats ({am})', '{nicks_amp} - misc stats ({am})'],
		];
		$descriptions = [
			'profile' => ['{nick}&rsquo;s profile', 'Comparison of {nicks_and}&rsquo;s profiles'],
			'list' => ['{nick}&rsquo;s {am} list', 'Comparison of {nicks_and}&rsquo;s {am} lists'],
			'rati' => ['{nick}&rsquo;s {am} rating statistics', 'Comparison of {nicks_and}&rsquo;s {am} rating statistics'],
			'acti' => ['{nick}&rsquo;s {am} activity', 'Comparison of {nicks_and}&rsquo;s {am} activity'],
			'achi' => ['{nick}&rsquo;s {am} achievements', 'Comparison of {nicks_and}&rsquo;s {am} achievements'],
			'sug' => ['{nick}&rsquo;s {am} suggestions', 'Comparison of {nicks_and}&rsquo;s {am} suggestions'],
			'favs' => ['{nick}&rsquo;s {am} favorites', 'Comparison of {nicks_and}&rsquo;s {am} favorites'],
			'misc' => ['{nick}&rsquo;s {am} misc stats', 'Comparison of {nicks_and}&rsquo;s {am} misc stats'],
		];
		$tokens = [
			'nick' => $this->view->users[0]->getPublicName(),
			'nicks_amp' => implode(' & ', array_map(function($user) { return $user->getPublicName(); }, $this->view->users)),
			'nicks_and' => implode(' and ', array_map(function($user) { return $user->getPublicName(); }, $this->view->users)),
			'am' => $this->mgHelper->textAM()
		];

		if (isset($titles[$this->view->actionName])) {
			$title = 'MALgraph - ';
			$description = '';
			if (count($this->view->users) > 1) {
				$title .= $titles[$this->view->actionName][1];
				$description .= $descriptions[$this->view->actionName][1];
			} else {
				$title .= $titles[$this->view->actionName][0];
				$description .= $descriptions[$this->view->actionName][0];
			}
			$title = $this->mgHelper->replaceTokens($title, $tokens);
			$description = $this->mgHelper->replaceTokens($description, $tokens);

			HeadHelper::setTitle($title);
			HeadHelper::setDescription($description);
			HeadHelper::addKeywords(['profile', 'list', 'achievements', 'ratings', 'activity', 'favorites', 'suggestions', 'recommendations']);
			foreach ($this->view->userNames as $userName) {
				if ($userName{0} != '=') {
					HeadHelper::addKeywords([$userName]);
				}
			}
		}
	}


	public function profileAction() {
		MediaHelper::addMedia([MediaHelper::HIGHCHARTS]);
		$this->view->profileInfo = [];

		foreach ($this->view->users as $u) {
			$info = [];
			foreach (AMModel::getTypes() as $type) {
				$info[$type] = new StdClass;
				$info[$type]->completed = 0;
				$info[$type]->eps = 0;
				$info[$type]->epsMismatched = [];

				$entriesNonPlanned = $u->getList($type)->getEntries(UserListFilters::getNonPlanned());
				$entriesCompleted = $u->getList($type)->getEntries(UserListFilters::getCompleted());
				$scoreDistribution = new ScoreDistribution();
				$lengthDistribution = new LengthDistribution();
				$subTypeDistribution = new SubTypeDistribution();
				foreach ($entriesCompleted as $entry) {
					$info[$type]->completed += 1;
					if ($entry->getAMEntry()->getSubType() != AnimeEntry::SUBTYPE_MOVIE) {
						$lengthDistribution->addEntry($entry);
					}
					$subTypeDistribution->addEntry($entry);
				}
				foreach ($entriesNonPlanned as $entry) {
					$scoreDistribution->addEntry($entry);
					if ($type == AMModel::TYPE_ANIME) {
						$a = $entry->getCompletedEpisodes();
						$b = $entry->getAMEntry()->getEpisodeCount();
					} else {
						$a = $entry->getCompletedChapters();
						$b = $entry->getAMEntry()->getChapterCount();
					}
					$info[$type]->eps += $a;
					if ($a != $b and $entry->getStatus() == UserListEntry::STATUS_COMPLETED) {
					}
				}
				$subTypeDistribution->finalize();
				$info[$type]->lengthDistribution = $lengthDistribution;
				$info[$type]->scoreDistribution = $scoreDistribution;
				$info[$type]->subTypeDistribution = $subTypeDistribution;
				$info[$type]->epsMismatched = UserListService::getMismatchedEntries($entriesNonPlanned);
				$info[$type]->franchises = UserListService::getFranchises($entriesNonPlanned);
			}
			$this->view->profileInfo[$u->getRuntimeID()] = $info;

		}
		require_once ChibiConfig::getInstance()->chibi->runtime->rootFolder . '/src/models/globals.php';
		$this->view->globalInfo = GlobalsModel::getData();

	}



	public function listAction() {
		MediaHelper::addMedia([MediaHelper::TABLESORTER]);
	}



	public function achiAction() {
		$achList = ChibiRegistry::getHelper('mg')->loadJSON(ChibiConfig::getInstance()->chibi->runtime->rootFolder . DIRECTORY_SEPARATOR . ChibiConfig::getInstance()->misc->achDefFile);

		$imgFiles = scandir(ChibiConfig::getInstance()->chibi->runtime->rootFolder . '/media/img/ach');
		$getThreshold = function($ach) {
			if (preg_match('/^([0-9.]+)\+$/', $ach['threshold'], $matches)) {
				return [floatval($matches[1]), null];
			} elseif (preg_match('/^([0-9.]+)(\.\.|-)([0-9.]+)$/', $ach['threshold'], $matches)) {
				return [floatval($matches[1]), floatval($matches[3])];
			}
			throw new Exception('Invalid threshold: ' . $ach['threshold']);
		};

		foreach ($this->view->users as $i => $u) {
			$entriesCompleted = $u->getList($this->view->am)->getEntries(UserListFilters::getCompleted());
			$entriesNonPlanned = $u->getList($this->view->am)->getEntries(UserListFilters::getNonPlanned());
			$achievements = [];

			foreach ($achList[$this->view->am] as $group => $groupData) {
				//get subject and entries basing on requirement type
				$subject = null;
				$entriesOwned = null;
				switch ($groupData['requirement']['type']) {
					case 'given-titles':
						$entriesOwned = array();
						foreach ($entriesCompleted as $e) {
							if (in_array($e->getID(), $groupData['requirement']['titles'])) {
								$entriesOwned []= $e;
							}
						}
						$subject = count($entriesOwned);
						break;
					case 'genre-titles':
						$filter = UserListFilters::getGenre($groupData['requirement']['genre']);
						$entriesOwned = array();
						foreach ($entriesCompleted as $e) {
							if ($filter($e)) {
								$entriesOwned []= $e;
							}
						}
						$subject = count($entriesOwned);
						break;
					case 'completed-titles':
						$subject = count($entriesCompleted);
						break;
					case 'mean-score':
						$distribution = new ScoreDistribution($entriesNonPlanned);
						if ($distribution->getRatedCount() > 0) {
							$subject = $distribution->getMeanScore();
						}
						break;
					default:
						throw new Exception('Invalid requirement: ' . $groupData['requirement']['type']);
				}

				if ($subject === null) {
					continue;
				}

				//give first achievement for which the subject fits into its threshold
				$nextAch = null;
				foreach (array_reverse($groupData['achievements']) as $ach) {
					list($a, $b) = $getThreshold($ach);

					if ((($subject >= $a) or ($a === null)) and (($subject <= $b) or ($b === null))) {
						//put additional info
						$ach['a'] = $a;
						$ach['b'] = $b;
						if (!empty($entriesOwned)) {
							uasort($entriesOwned, UserListSorters::getByTitle());
							$ach['entries'] = $entriesOwned;
						}
						foreach ($imgFiles as $f) {
							if (preg_match('/' . $ach['id'] . '[^0-9a-zA-Z_-]/', $f)) {
								$ach['path'] = $f;
							}
						}
						$ach['progress'] = 100;
						$ach['progress-subject'] = round($subject, 2);
						if ($nextAch !== null) {
							list ($nextA, $nextB) = $getThreshold($nextAch);
							$ach['progress'] = ($subject - $a) * 100.0 / ($nextA - $a);
							$ach['progress-next-a'] = $nextA;
							$ach['progress-next-b'] = $nextB;
						}
						$achievements []= $ach;
						break;
					}
					$nextAch = $ach;
				}
			}

			$this->view->achievements[$u->getID()] = $achievements;
		}
	}



	public function ratiAction() {
		MediaHelper::addMedia([MediaHelper::HIGHCHARTS,
			MediaHelper::POPUPS,
			MediaHelper::INFOBOX,
			MediaHelper::FARBTASTIC
		]);

		$this->view->scoreDistribution = [];
		foreach ($this->view->users as $userEntry) {
			$filter = UserListFilters::getNonPlanned();
			$entries = $userEntry->getList($this->view->am)->getEntries($filter);

			$this->view->scoreDistribution[$userEntry->getID()] = new ScoreDistribution($entries);
			$this->view->scoreDurationDistribution[$userEntry->getID()] = new ScoreDurationDistribution($entries);

			//add some random information
			list($year, $month, $day) = explode('-', $userEntry->getUserData()->getJoinDate());
			$earliest = mktime(0, 0, 0, $month, $day, $year);
			$totalTime = 0;
			foreach ($entries as $e) {
				$totalTime += $e->getCompletedDuration();
				foreach ([$e->getStartDate(), $e->getFinishDate()] as $k) {
					$f = explode('-', $k);
					if (count($f) != 3) {
						continue;
					}
					$year = intval($f[0]);
					$month = intval($f[1]);
					$day = intval($f[2]);
					$time = mktime(0, 0, 0, $month, $day, $year);
					if ($time < $earliest) {
						$earliest = $time;
					}
				}
			}
			$this->view->earliestTimeKnown[$userEntry->getID()] = $earliest;
			$this->view->meanTime[$userEntry->getID()] = $totalTime / max(1, (time() - $earliest) / (24. * 3600.0));
		}
	}



	public function ratiImgAction() {
		//prepare info for view
		if (count($this->view->users) > 1) {
			throw new Exception('Only one user is supported here');
		}
		$user = $this->view->users[0];
		foreach (array(AMModel::TYPE_ANIME, AMModel::TYPE_MANGA) as $am) {
			$filter = UserListFilters::getNonPlanned();
			$entries = $user->getList($am)->getEntries($filter);
			$this->view->scoreDistribution[$am] = new ScoreDistribution($entries);
		}

		//define some handy constants
		define('IMAGE_TYPE_ANIME', 1);
		define('IMAGE_TYPE_MANGA', 2);
		define('IMAGE_TYPE_ANIME_MANGA', 3);

		define('COLOR_BARS_1', 0);
		define('COLOR_BARS_2', 1);
		define('COLOR_BAR_GUIDES_1', 2);
		define('COLOR_BAR_GUIDES_2', 3);
		define('COLOR_BACKGROUND', 4);
		define('COLOR_BACKGROUND2', 5);
		define('COLOR_FONT_DARK', 6);
		define('COLOR_FONT_LIGHT', 7);
		define('COLOR_TITLE', 8);
		define('COLOR_LOGO', 9);

		//get input data from GET
		$this->view->user = reset($this->view->users);
		if (!empty($_GET['type'])) {
			switch ($_GET['type']) {
				case '2': $this->view->imageType = IMAGE_TYPE_MANGA; break;
				case '3': $this->view->imageType = IMAGE_TYPE_ANIME_MANGA; break;
				default: $this->view->imageType = IMAGE_TYPE_ANIME; break;
			}
		}

		$this->view->colors = [
			COLOR_BARS_1 =>       array('a' => 0x00, 'r' => 0xa4, 'g' => 0xc0, 'b' => 0xf4),
			COLOR_BARS_2 =>       array('a' => 0x00, 'r' => 0x13, 'g' => 0x45, 'b' => 0x9a),
			COLOR_BAR_GUIDES_1 => array('a' => 0xee, 'r' => 0xa4, 'g' => 0xc0, 'b' => 0xf4),
			COLOR_BAR_GUIDES_2 => array('a' => 0xee, 'r' => 0x13, 'g' => 0x45, 'b' => 0x9a),
			COLOR_BACKGROUND =>   array('a' => 0xff, 'r' => 0xff, 'g' => 0xff, 'b' => 0xff),
			COLOR_FONT_DARK =>    array('a' => 0x00, 'r' => 0x00, 'g' => 0x00, 'b' => 0x00),
			COLOR_FONT_LIGHT =>   array('a' => 0xaa, 'r' => 0x00, 'g' => 0x00, 'b' => 0x00),
			COLOR_TITLE =>        array('a' => 0x00, 'r' => 0x57, 'g' => 0x7f, 'b' => 0xc2),
		];

		$defs = [
			COLOR_BARS_1 => 'bar1',
			COLOR_BARS_2 => 'bar2',
			COLOR_BAR_GUIDES_1 => 'line1',
			COLOR_BAR_GUIDES_2 => 'line2',
			COLOR_BACKGROUND => 'back',
			COLOR_BACKGROUND2 => 'back2',
			COLOR_FONT_DARK => 'font1',
			COLOR_FONT_LIGHT => 'font2',
			COLOR_TITLE => 'title',
			COLOR_LOGO => 'logo'
		];

		//sanitize input
		foreach ($defs as $key => $constant) {
			if (isset($_GET[$constant])) {
				$value = $_GET[$constant];
				if (strlen($value) != 6 and strlen($value) != 8) {
					throw new Exception('Wrong length for ' . $constant . ' (expected 8 or 6 characters)');
				}
				$value = array_map('hexdec', str_split($value, 2));
				if (count($value) == 4) {
					list($a, $r, $g, $b) = $value;
				} else {
					$a = 0;
					list($r, $g, $b) = $value;
				}
				$this->view->colors[$key] = array('a' => $a, 'r' => $r, 'g' => $g, 'b' => $b);
			}
		}

		if (empty($this->view->colors[COLOR_BACKGROUND2])) {
			$this->view->colors[COLOR_BACKGROUND2] = $this->view->colors[COLOR_BACKGROUND];
		}
		if (empty($this->view->colors[COLOR_LOGO])) {
			$this->view->colors[COLOR_LOGO] = $this->view->colors[COLOR_TITLE];
		}

		//finally render image
		ChibiConfig::getInstance()->chibi->runtime->layoutName = null;
		header('Content-type: image/png');
		header('Cache-Control: no-cache, must-revalidate');
		header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
		$this->view->render();

		//refresh user data AFTER rendering image
		//because this is an image, we don't send ajax refresh requests
		//and since they can stop visiting the site except for this image,
		//we gotta do an update HERE, where we present image to given user
		$modelUsers = new UserModel();
		try {
			$user = $modelUsers->get($user->getUserName(), AbstractModel::CACHE_POLICY_DEFAULT);
		} catch (Exception $e) {
		}
	}



	public function actiAction() {
		MediaHelper::addMedia([MediaHelper::HIGHCHARTS, MediaHelper::INFOBOX]);

		foreach ($this->view->users as $i => $u) {
			//count completed within month periods
			$monthPeriod = false;
			$monthPeriodMin = false;
			$monthPeriodMax = false;
			$monthPeriods = [];

			$filter = UserListFilters::getCompleted();
			$entries = $u->getList($this->view->am)->getEntries($filter);
			foreach ($entries as $e) {
				$monthPeriod = UserListService::getMonthPeriod($e);
				if (!isset($monthPeriods[$monthPeriod])) {
					$monthPeriods[$monthPeriod] = [
						'duration' => 0,
						'entries' => []
					];
				}
				$monthPeriods[$monthPeriod]['entries'] []= $e;
				if ($monthPeriod != '?') {
					if ($monthPeriodMin === false or strcmp($monthPeriod, $monthPeriodMin) < 0) {
						$monthPeriodMin = $monthPeriod;
					}
					if ($monthPeriodMax === false or strcmp($monthPeriod, $monthPeriodMax) > 0) {
						$monthPeriodMax = $monthPeriod;
					}
				}
				$monthPeriods[$monthPeriod]['duration'] += $e->getCompletedDuration();
			}

			//add empty month periods so graph has no gaps
			if (!empty($monthPeriodMin)) {
				list($yearMin, $monthMin) = explode('-', $monthPeriodMin);
				$yearMax = date('Y');
				$monthMax = date('m');
				$keys = [];
				for ($month = $monthMin; $month <= 12; $month ++) {
					$keys []= sprintf('%04d-%02d', $yearMin, $month);
				}
				for ($year = $yearMin + 1; $year < $yearMax; $year ++) {
					for ($month = 1; $month <= 12; $month ++) {
						$keys []= sprintf('%04d-%02d', $year, $month);
					}
				}
				for ($month = 1; $month <= $monthMax; $month ++) {
					$keys []= sprintf('%04d-%02d', $yearMax, $month);
				}
				foreach ($keys as $key) {
					if (!isset($monthPeriods[$key])) {
						$monthPeriods[$key] = [
							'duration' => 0,
							'entries' => []
						];
					}
				}
				uksort($monthPeriods, function($a, $b) {
					if ($a == '?') {
						return -1;
					} elseif ($b == '?') {
						return 1;
					}
					return strcmp($a, $b);
				});
			}

			//day periods
			$dayPeriods = [];
			$dayPeriodTitles = [];
			for ($daysBack = 21; $daysBack >= 0; $daysBack --) {
				$dayPeriod = [];
				foreach ($u->getHistory($this->view->am)->getEntriesByDaysAgo($daysBack) as $entry) {
					$dayPeriod []= $entry;
					$dayPeriodTitles[$entry->getID()] = $entry;
				}
				$dayPeriods[$daysBack] = $dayPeriod;
			}

			$this->view->monthPeriods[$u->getID()] = $monthPeriods;
			$this->view->dayPeriods[$u->getID()] = $dayPeriods;
			$this->view->dayPeriodTitles[$u->getID()] = $dayPeriodTitles;
		}
	}



	public function favsAction() {
		MediaHelper::addMedia([MediaHelper::HIGHCHARTS, MediaHelper::TABLESORTER]);

		foreach ($this->view->users as $user) {
			$filter = UserListFilters::getNonPlanned();
			$entries = $user->getList($this->view->am)->getEntries($filter);

			$this->view->favCreators[$user->getID()] = new CreatorDistribution($entries);
			$this->view->favGenres[$user->getID()] = new GenreDistribution($entries);
			$this->view->favYears[$user->getID()] = new YearDistribution($entries);
			$this->view->favDecades[$user->getID()] = new DecadeDistribution($entries);

			$this->view->yearScores[$user->getID()] = [];
			foreach ($this->view->favYears[$user->getID()]->getGroupsKeys(Distribution::IGNORE_NULL_KEY) as $key) {
				$subEntries = $this->view->favYears[$user->getID()]->getGroupEntries($key);
				$this->view->yearScores[$user->getID()][$key] = UserListService::getMeanScore($subEntries);
			}

			$this->view->decadeScores[$user->getID()] = [];
			foreach ($this->view->favDecades[$user->getID()]->getGroupsKeys(Distribution::IGNORE_NULL_KEY) as $key) {
				$subEntries = $this->view->favDecades[$user->getID()]->getGroupEntries($key);
				$this->view->decadeScores[$user->getID()][$key] = UserListService::getMeanScore($subEntries);
			}

			$this->view->creatorScores[$user->getID()] = [];
			$this->view->creatorTimeSpent[$user->getID()] = [];
			foreach ($this->view->favCreators[$user->getID()]->getGroupsKeys(Distribution::IGNORE_NULL_KEY) as $key) {
				$subEntries = $this->view->favCreators[$user->getID()]->getGroupEntries($key);
				$this->view->creatorScores[$user->getID()][$key->getID()] = UserListService::getMeanScore($subEntries);
				$this->view->creatorTimeSpent[$user->getID()][$key->getID()] = UserListService::getTimeSpent($subEntries);
			}

			$this->view->genreScores[$user->getID()] = [];
			$this->view->genreTimeSpent[$user->getID()] = [];
			foreach ($this->view->favGenres[$user->getID()]->getGroupsKeys(Distribution::IGNORE_NULL_KEY) as $key) {
				$subEntries = $this->view->favGenres[$user->getID()]->getGroupEntries($key);
				$this->view->genreScores[$user->getID()][$key->getID()] = UserListService::getMeanScore($subEntries);
				$this->view->genreTimeSpent[$user->getID()][$key->getID()] = UserListService::getTimeSpent($subEntries);
			}
		}
	}



	public function sugAction() {
		foreach ($this->view->users as $u) {
			$filter = UserListFilters::getCompleted();
			$entries = $u->getList($this->view->am)->getEntries($filter);
			$limit = 15;

			$this->view->recsStatic = count($entries) <= 20;
			$this->sessionHelper->restore();
			$sessionKey = 'recs-' . $this->view->am . '-' . $u->getID();
			if (isset($_SESSION[$sessionKey])) {
				$this->view->recs[$u->getID()] = unserialize($_SESSION[$sessionKey]);
				continue;
			}
			$this->sessionHelper->close();

			$recs = [];
			$modelAM = AMModel::factory($this->view->am);
			//static recommendations
			if ($this->view->recsStatic) {
				$staticRecs = ChibiRegistry::getHelper('mg')->loadJSON(ChibiConfig::getInstance()->chibi->runtime->rootFolder . DIRECTORY_SEPARATOR . ChibiConfig::getInstance()->misc->staticRecsDefFile);
				foreach ($staticRecs[$this->view->am] as $id) {
					$userEntry = $u->getList($this->view->am)->getEntryByID($id);
					if ($userEntry !== null and $userEntry->getStatus() == UserListEntry::STATUS_COMPLETED) {
						continue;
					}
					$recs []= $modelAM->get($id, AbstractModel::CACHE_POLICY_FORCE_CACHE);
				}
				shuffle($recs);
				$recs = array_slice($recs, 0, $limit);

			//dynamic recommendations
			} else {
				$goal = 50;
				$selUsers = [];
				$selAM = [];

				$u1 = $u;
				$list1 = $u1->getList($this->view->am);
				$mean1 = UserListService::getMeanScore($entries);
				$filterDontShow = function($e) {
					if (!$e) return true;
					if ($e->getStatus() != UserListEntry::STATUS_PLANNED) return true;
					return false;
				};

				//1. get some user base
				$modelUsers = new UserModel();
				$allIds = $modelUsers->getKeys();
				shuffle($allIds);
				$iters = 0;
				while (count($allIds) > 0 and count($selUsers) < $goal) {
					$iters ++;
					$id = array_shift($allIds);
					if (!isset($selUsers[$id])) {
						$u2 = $modelUsers->get($id, AbstractModel::CACHE_POLICY_FORCE_CACHE);
						$list2 = $u2->getList($this->view->am);
						$distro = $list2->getScoreDistributionForCF();
						//filter out uninteresting sources
						if ($distro->getRatedCount() >= 50 and $distro->getStandardDeviation() >= 1.5) {
							$mean2 = $distro->getMeanScore();
							$selUsers[$id] = [$u2, $list2, $mean2];
						} else {
							unset($u2, $list2, $distro);
						}
					}
				}

				//2.
				#$simNormalize = 0;
				foreach ($selUsers as $id => &$data) {
					//2a. get similarity indexes between me and selected user
					list ($u2, $list2, $mean2) = $data;
					$sum1 = $sum2a = $sum2b = 0;
					foreach ($entries as $e1) {
						$e2 = $list2->getEntryByID($e1->getID());
						if ($e2 !== null) {
							$score1 = $e1->getScore();
							$score2 = $e2->getScore();
							$sum1 += ($score1 - $mean1) * ($score2 - $mean2);
							$sum2a += ($score1 - $mean1) * ($score1 - $mean1);
							$sum2b += ($score2 - $mean2) * ($score2 - $mean2);
						}
					}
					$sim = $sum1 / max(1, sqrt($sum2a * $sum2b));
					$data []= $sim;

					//2b. check what titles are on their list
					foreach ($list2->getEntries() as $e2) {
						$e1 = $list1->getEntryByID($e2->getID());
						if (!$filterDontShow($e1)) {
							$selAM []= $e2->getID();
						}
					}
					#$simNormalize += abs($sim);
				}
				#$simNormalize = 1. / max(1, $simNormalize);

				//3. get title ratings
				$finalAM = [];
				foreach ($selAM as $id) {
					$score = 0;
					foreach ($selUsers as $uid => $data) {
						list ($u2, $list2, $mean2, $sim) = $data;
						$e2 = $list2->getEntryByID($id);
						//filter sources
						if ($e2 and $e2->getStatus() == UserListEntry::STATUS_COMPLETED) {
							$score2 = $e2->getScore();
							if ($score2) {
								$score += $sim * ($score2 - $mean2);
							}
						}
					}
					#$score *= $simNormalize;
					$score += $mean1;
					$finalAM[$id] = $score;
				}

				//4. convert title ratings to actual recommendations
				arsort($finalAM, SORT_NUMERIC);
				$finalAM = array_keys($finalAM);
				while (count($finalAM) > 0 and count($recs) < $limit) {
					//make sure only first unwatched thing in franchise is going to be recommended
					$franchise = $modelAM->get(array_shift($finalAM))->getFranchise();
					uasort($franchise->entries, function($a, $b) { return $a->getID() > $b->getID() ? 1 : -1; });
					$e = null;
					foreach ($franchise->entries as $e2) {
						if ($e2->getStatus() == AMEntry::STATUS_NOT_YET_PUBLISHED) {
							continue;
						}
						$e1 = $list1->getEntryByID($e2->getID());
						if (!$filterDontShow($e1)) {
							$e = $e2;
							break;
						}
					}
					if (empty($e)) {
						continue;
					}
					//don't recommend more than one thing in given franchise
					foreach ($franchise->entries as $e2) {
						$id2 = $e2->getID();
						$finalAM = array_filter($finalAM, function($id) use ($id2) { return $id != $id2; });
					}
					$recs []= $e;
				}
			}

			$this->sessionHelper->restore();
			$_SESSION[$sessionKey] = serialize($recs);
			$this->view->recs[$u->getID()] = $recs;
		}

		foreach ($this->view->users as $u) {
			$relations = UserListService::getFranchises($entries, null);
			//remove ids user has seen
			foreach ($relations as $franchise) {
				foreach ($franchise->ownEntries as $ownEntry) {
					unset($franchise->entries[$ownEntry->getID()]);
				}
			}
			//filter entries that might be empty after removal
			$relations = array_filter($relations, function($a) {
				return count($a->entries) > 0;
			});
			//sort titles
			foreach ($relations as $franchise) {
				$franchise->meanScore = UserListService::getMeanScore($franchise->ownEntries);
				uasort($franchise->entries, function($a, $b) {
					return $a->getID() - $b->getID();
				});
			}
			uasort($relations, function($a, $b) { return $b->meanScore > $a->meanScore; });

			$this->view->relations[$u->getID()] = $relations;
		}
	}
}

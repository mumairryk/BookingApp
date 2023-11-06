<?php

namespace DTApi\Repository;

use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{

    protected $model;
    protected $mailer;
    protected $logger;

    /**
     * @param Job $model
     */
    function __construct(Job $model, MailerInterface $mailer)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->logger = new Logger('admin_logger');

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    /**
     * @param $user_id
     * @return array
     */
    public function getUsersJobs($user_id)
    {
        $cuser = User::find($user_id);
        $usertype = '';
        $emergencyJobs = [];
        $normalJobs = [];
        if ($cuser) {
            if ($cuser->is('customer')) {
                $jobs = $cuser->jobs()
                    ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')
                    ->whereIn('status', ['pending', 'assigned', 'started'])
                    ->orderBy('due', 'asc')
                    ->get();
                $usertype = 'customer';
            } elseif ($cuser->is('translator')) {
                $jobs = Job::getTranslatorJobs($cuser->id, 'new');
                $jobs = $jobs->pluck('jobs')->all();
                $usertype = 'translator';
            }
            if ($jobs) {
                foreach ($jobs as $jobitem) {
                    if ($jobitem->immediate == 'yes') {
                        $emergencyJobs[] = $jobitem;
                    } else {
                        $normalJobs[] = $jobitem;
                    }
                }
                $normalJobs = collect($normalJobs)->each(function ($item) use ($user_id) {
                    $item['usercheck'] = Job::checkParticularJob($user_id, $item);
                })->sortBy('due')->all();
            }
        }
        return ['emergencyJobs' => $emergencyJobs, 'normalJobs' => $normalJobs, 'cuser' => $cuser, 'usertype' => $usertype];
    }


    /**
     * @param $user_id
     * @return array
     */
    public function getUsersJobsHistory($user_id, Request $request)
    {
        $page = $request->get('page');
        $pagenum = isset($page) ? $page : 1;
        $cuser = User::find($user_id);
        $emergencyJobs = [];
        if ($cuser) {
            if ($cuser->is('customer')) {
                $jobs = $cuser->jobs()
                    ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
                    ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
                    ->orderBy('due', 'desc')
                    ->paginate(15);
                $usertype = 'customer';
                return ['emergencyJobs' => $emergencyJobs, 'normalJobs' => [], 'jobs' => $jobs, 'cuser' => $cuser, 'usertype' => $usertype, 'numpages' => 0, 'pagenum' => 0];
            } elseif ($cuser->is('translator')) {
                $jobs_ids = Job::getTranslatorJobsHistoric($cuser->id, 'historic', $pagenum);
                $totaljobs = $jobs_ids->total();
                $numpages = ceil($totaljobs / 15);
                $usertype = 'translator';
                $jobs = $jobs_ids;
                $normalJobs = $jobs_ids;
                return ['emergencyJobs' => $emergencyJobs, 'normalJobs' => $normalJobs, 'jobs' => $jobs, 'cuser' => $cuser, 'usertype' => $usertype, 'numpages' => $numpages, 'pagenum' => $pagenum];
            }
        }
    }


    /**
     * @param $user
     * @param $data
     * @return mixed
     */
    public function store($user, $data)
    {
        $immediateTime = 5;
        $consumerType = $user->userMeta->consumer_type;

        if (!$user->isCustomer()) {
            return $this->failResponse("Translator cannot create booking");
        }

        $cuser = $user;

        if (!$this->validateData($data)) {
            return $this->failResponse("You must fill in all required fields");
        }

        if ($data['immediate'] == 'yes') {
            $dueCarbon = Carbon::now()->addMinute($immediateTime);
            $data['due'] = $dueCarbon->format('Y-m-d H:i:s');
            $data['immediate'] = 'yes';
            $data['customer_phone_type'] = 'yes';
            $responseType = 'immediate';
        } else {
            $due = $data['due_date'] . " " . $data['due_time'];
            $responseType = 'regular';
            $dueCarbon = Carbon::createFromFormat('m/d/Y H:i', $due);
            $data['due'] = $dueCarbon->format('Y-m-d H:i:s');

            if ($dueCarbon->isPast()) {
                return $this->failResponse("Can't create booking in the past");
            }
        }

        $data['certified'] = $this->getCertifiedType($data['job_for']);
        $data['job_type'] = $this->getJobType($consumerType);
        $data['b_created_at'] = now();

        if (isset($due)) {
            $data['will_expire_at'] = TeHelper::willExpireAt($due, $data['b_created_at']);
        }

        $data['by_admin'] = $data['by_admin'] ?? 'no';

        $job = $cuser->jobs()->create($data);

        $response = [
            'status' => 'success',
            'id' => $job->id,
        ];

        $response['job_for'] = $this->getJobForArray($job);
        $response['customer_town'] = $cuser->userMeta->city;
        $response['customer_type'] = $cuser->userMeta->customer_type;

        // Event::fire(new JobWasCreated($job, $data, '*'));
        // $this->sendNotificationToSuitableTranslators($job->id, $data, '*');

        return $response;
    }

    private function validateData($data)
    {
        $requiredFields = ['from_language_id', 'due_date', 'due_time', 'duration'];
        if ($data['immediate'] == 'no') {
            $requiredFields = array_merge($requiredFields, ['customer_phone_type', 'customer_physical_type']);
        }

        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                return false;
            }
        }

        return true;
    }

    private function failResponse($message)
    {
        return ['status' => 'fail', 'message' => $message];
    }

    private function getCertifiedType($jobFor)
    {
        if (in_array('normal', $jobFor) && in_array('certified', $jobFor)) {
            return 'both';
        }

        return in_array('certified', $jobFor) ? 'yes' : $jobFor[0];
    }

    private function getJobType($consumerType)
    {
        switch ($consumerType) {
            case 'rwsconsumer':
                return 'rws';
            case 'ngo':
                return 'unpaid';
            case 'paid':
                return 'paid';
        }

        return '';
    }

    private function getJobForArray($job)
    {
        $jobFor = [];
        if ($job->gender != null) {
            $jobFor[] = ($job->gender == 'male') ? 'Man' : 'Kvinna';
        }
        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $jobFor[] = 'normal';
                $jobFor[] = 'certified';
            } else {
                $jobFor[] = $job->certified;
            }
        }
        return $jobFor;
    }


    /**
     * @param $data
     * @return mixed
     */
    public function storeJobEmail($data)
    {
        $job = Job::findOrFail($data['user_email_job_id']);
        $this->updateJobDetails($job, $data);

        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;

        $this->sendJobCreatedEmail($email, $name, $job, $user);

        $response = [
            'type' => $data['user_type'],
            'job' => $job,
            'status' => 'success',
        ];

        Event::fire(new JobWasCreated($job, $this->jobToData($job), '*'));

        return $response;
    }

    private function updateJobDetails($job, $data)
    {
        $job->user_email = $data['user_email'] ?? '';
        $job->reference = $data['reference'] ?? '';

        if (isset($data['address'])) {
            $user = $job->user()->first();
            $job->address = $data['address'] ?: $user->userMeta->address;
            $job->instructions = $data['instructions'] ?: $user->userMeta->instructions;
            $job->town = $data['town'] ?: $user->userMeta->city;
        }

        $job->save();
    }

    private function sendJobCreatedEmail($email, $name, $job, $user)
    {
        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;
        $send_data = ['user' => $user, 'job' => $job];
        $this->mailer->send($email, $name, $subject, 'emails.job-created', $send_data);
    }


    /**
     * @param $job
     * @return array
     */
    public function jobToData($job)
    {
        $data = [
            'job_id' => $job->id,
            'from_language_id' => $job->from_language_id,
            'immediate' => $job->immediate,
            'duration' => $job->duration,
            'status' => $job->status,
            'gender' => $job->gender,
            'certified' => $job->certified,
            'due' => $job->due,
            'job_type' => $job->job_type,
            'customer_phone_type' => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town' => $job->town,
            'customer_type' => $job->user->userMeta->customer_type,
            'job_for' => [],
        ];

        $dueDateTime = explode(" ", $job->due);
        $data['due_date'] = $dueDateTime[0];
        $data['due_time'] = $dueDateTime[1];

        if ($job->gender != null) {
            $data['job_for'][] = ($job->gender == 'male') ? 'Man' : 'Kvinna';
        }

        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'Godkänd tolk';
                $data['job_for'][] = 'Auktoriserad';
            } else if ($job->certified == 'yes') {
                $data['job_for'][] = 'Auktoriserad';
            } else if ($job->certified == 'n_health') {
                $data['job_for'][] = 'Sjukvårdstolk';
            } else if ($job->certified == 'law' || $job->certified == 'n_law') {
                $data['job_for'][] = 'Rättstolk';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }

        return $data;
    }


    /**
     * @param array $post_data
     */
    public function jobEnd($post_data = [])
    {
        $completedDate = now();
        $jobId = $post_data["job_id"];
        $jobDetail = Job::with('translatorJobRel')->find($jobId);
        $dueDate = $jobDetail->due;
        $start = Carbon::parse($dueDate);
        $end = Carbon::parse($completedDate);
        $interval = $end->diff($start)->format('%h:%i:%s');

        $job = $jobDetail;
        $job->end_at = $completedDate;
        $job->status = 'completed';
        $job->session_time = $interval;

        $user = $job->user->first();
        $email = $job->user_email ?? $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;

        $sessionTimeExplode = explode(':', $job->session_time);
        $sessionTimeFormatted = $sessionTimeExplode[0] . ' tim ' . $sessionTimeExplode[1] . ' min';

        $data = [
            'user' => $user,
            'job' => $job,
            'session_time' => $sessionTimeFormatted,
            'for_text' => 'faktura',
        ];

        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $job->save();

        $translatorJob = $job->translatorJobRel->where('completed_at', null)->where('cancel_at', null)->first();

        Event::fire(new SessionEnded($job, ($post_data['userid'] == $job->user_id) ? $translatorJob->user_id : $job->user_id));

        $translator = $translatorJob->user->first();
        $email = $translator->email;
        $name = $translator->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;

        $data = [
            'user' => $translator,
            'job' => $job,
            'session_time' => $sessionTimeFormatted,
            'for_text' => 'lön',
        ];

        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $translatorJob->completed_at = $completedDate;
        $translatorJob->completed_by = $post_data['userid'];
        $translatorJob->save();
    }


    /**
     * Function to get all Potential jobs of user with his ID
     * @param $user_id
     * @return array
     */
    public function getPotentialJobIdsWithUserId($user_id)
    {
        $userMeta = UserMeta::where('user_id', $user_id)->first();
        $translatorType = $userMeta->translator_type;
        $jobType = $translatorType === 'professional' ? 'paid' : ($translatorType === 'rwstranslator' ? 'rws' : 'unpaid');

        $languages = UserLanguages::where('user_id', $user_id)->pluck('lang_id')->all();
        $gender = $userMeta->gender;
        $translatorLevel = $userMeta->translator_level;

        $jobIds = Job::getJobs($user_id, $jobType, 'pending', $languages, $gender, $translatorLevel);

        $jobIds = array_filter($jobIds, function ($job) use ($user_id) {
            $checkTown = Job::checkTowns($job->user_id, $user_id);
            return !($job->customer_phone_type === 'no' || $job->customer_phone_type === '') &&
                $job->customer_physical_type === 'yes' && $checkTown === false;
        });

        return TeHelper::convertJobIdsInObjs($jobIds);
    }

    /**
     * @param $job
     * @param array $data
     * @param $exclude_user_id
     */
    public function sendNotificationTranslator($job, $data = [], $excludeUserId)
    {
        $suitableTranslators = $this->findSuitableTranslators($job, $data, $excludeUserId);

        $msgContents = $this->getMessageContents($data);
        $msgText = [
            "en" => $msgContents
        ];

        $logger = $this->setupLogger();

        $logger->addInfo('Push send for job ' . $job->id, [$suitableTranslators, $msgText, $data]);

        $this->sendPushNotificationToSpecificUsers($suitableTranslators['regular'], $job->id, $data, $msgText, false);
        $this->sendPushNotificationToSpecificUsers($suitableTranslators['delayed'], $job->id, $data, $msgText, true);
    }

    private function findSuitableTranslators($job, $data, $excludeUserId)
    {
        $suitableTranslators = [
            'regular' => [],
            'delayed' => [],
        ];

        $users = User::where('user_type', '2')
            ->where('status', '1')
            ->where('id', '!=', $excludeUserId)
            ->get();

        foreach ($users as $user) {
            if (!$this->isNeedToSendPush($user->id)) {
                continue;
            }

            $notGetEmergency = TeHelper::getUsermeta($user->id, 'not_get_emergency');

            if ($data['immediate'] === 'yes' && $notGetEmergency === 'yes') {
                continue;
            }

            $potentialJobs = $this->getPotentialJobIdsWithUserId($user->id);

            foreach ($potentialJobs as $potentialJob) {
                if ($job->id == $potentialJob->id) {
                    $userId = $user->id;
                    $jobForTranslator = Job::assignedToPaticularTranslator($userId, $potentialJob->id);

                    if ($jobForTranslator === 'SpecificJob') {
                        $jobChecker = Job::checkParticularJob($userId, $potentialJob);

                        if ($jobChecker !== 'userCanNotAcceptJob') {
                            if ($this->isNeedToDelayPush($user->id)) {
                                $suitableTranslators['delayed'][] = $user;
                            } else {
                                $suitableTranslators['regular'][] = $user;
                            }
                        }
                    }
                }
            }
        }

        return $suitableTranslators;
    }

    private function getMessageContents($data)
    {
        if ($data['immediate'] === 'no') {
            return 'Ny bokning för ' . TeHelper::fetchLanguageFromJobId($data['from_language_id']) . 'tolk ' . $data['duration'] . 'min ' . $data['due'];
        }

        return 'Ny akutbokning för ' . TeHelper::fetchLanguageFromJobId($data['from_language_id']) . 'tolk ' . $data['duration'] . 'min';
    }

    private function setupLogger()
    {
        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());

        return $logger;
    }


    /**
     * Sends SMS to translators and retuns count of translators
     * @param $job
     * @return int
     */
    public function sendSMSNotificationToTranslator($job)
    {
        $translators = $this->getPotentialTranslators($job);
        $message = $this->prepareSMSMessage($job);

        $sentCount = $this->sendSMSMessagesToTranslators($translators, $message);

        return $sentCount;
    }

    private function prepareSMSMessage($job)
    {
        $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();

        $date = date('d.m.Y', strtotime($job->due));
        $time = date('H:i', strtotime($job->due));
        $duration = $this->convertToHoursMins($job->duration);
        $jobId = $job->id;
        $city = $job->city ? $job->city : $jobPosterMeta->city;

        $phoneJobMessageTemplate = trans('sms.phone_job', ['date' => $date, 'time' => $time, 'duration' => $duration, 'jobId' => $jobId]);
        $physicalJobMessageTemplate = trans('sms.physical_job', ['date' => $date, 'time' => $time, 'town' => $city, 'duration' => $duration, 'jobId' => $jobId]);

        $message = $this->determineMessageType($job);

        Log::info($message);

        return $message;
    }

    private function determineMessageType($job)
    {
        if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'no') {
            return trans('sms.physical_job');
        } else if ($job->customer_physical_type == 'no' && $job->customer_phone_type == 'yes') {
            return trans('sms.phone_job');
        } else if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'yes') {
            return trans('sms.phone_job');
        } else {
            return ''; // Handle the unexpected case
        }
    }

    private function sendSMSMessagesToTranslators($translators, $message)
    {
        $sentCount = 0;

        foreach ($translators as $translator) {
            $status = $this->sendSMS($translator, $message);
            Log::info('Send SMS to ' . $translator->email . ' (' . $translator->mobile . '), status: ' . print_r($status, true));
            $sentCount++;
        }

        return $sentCount;
    }

    private function sendSMS($translator, $message)
    {
        return SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
    }


    /**
     * Function to delay the push
     * @param $user_id
     * @return bool
     */
    public function isNeedToDelayPush($user_id)
    {
        if (!DateTimeHelper::isNightTime()) return false;
        $not_get_nighttime = TeHelper::getUsermeta($user_id, 'not_get_nighttime');
        if ($not_get_nighttime == 'yes') return true;
        return false;
    }

    /**
     * Function to check if need to send the push
     * @param $user_id
     * @return bool
     */
    public function isNeedToSendPush($user_id)
    {
        $not_get_notification = TeHelper::getUsermeta($user_id, 'not_get_notification');
        if ($not_get_notification == 'yes') return false;
        return true;
    }

    /**
     * Function to send Onesignal Push Notifications with User-Tags
     * @param $users
     * @param $job_id
     * @param $data
     * @param $msg_text
     * @param $is_need_delay
     */
    public function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay)
    {

        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job_id, [$users, $data, $msg_text, $is_need_delay]);
        if (env('APP_ENV') == 'prod') {
            $onesignalAppID = config('app.prodOnesignalAppID');
            $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config('app.prodOnesignalApiKey'));
        } else {
            $onesignalAppID = config('app.devOnesignalAppID');
            $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config('app.devOnesignalApiKey'));
        }
        $user_tags = $this->getUserTagsStringFromArray($users);
        $data['job_id'] = $job_id;
        $ios_sound = 'default';
        $android_sound = 'default';
        if ($data['notification_type'] == 'suitable_job') {
            if ($data['immediate'] == 'no') {
                $android_sound = 'normal_booking';
                $ios_sound = 'normal_booking.mp3';
            } else {
                $android_sound = 'emergency_booking';
                $ios_sound = 'emergency_booking.mp3';
            }
        }
        $fields = array(
            'app_id'         => $onesignalAppID,
            'tags'           => json_decode($user_tags),
            'data'           => $data,
            'title'          => array('en' => 'DigitalTolk'),
            'contents'       => $msg_text,
            'ios_badgeTpe'  => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound'  => $android_sound,
            'ios_sound'      => $ios_sound
        );
        if ($is_need_delay) {
            $next_business_time = DateTimeHelper::getNextBusinessTimeString();
            $fields['send_after'] = $next_business_time;
        }
        $fields = json_encode($fields);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', $onesignalRestAuthKey));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        $response = curl_exec($ch);
        $logger->addInfo('Push send for job ' . $job_id . ' curl answer', [$response]);
        curl_close($ch);
    }

    /**
     * @param Job $job
     * @return mixed
     */
    public function getPotentialTranslators(Job $job)
    {
        $job_type = $job->job_type;
        $translator_type = $this->mapJobTypeToTranslatorType($job_type);

        $joblanguage = $job->from_language_id;
        $gender = $job->gender;
        $translator_level = $this->getTranslatorLevels($job->certified);

        $blacklistedTranslators = UsersBlacklist::where('user_id', $job->user_id)->get();
        $blacklistedIds = collect($blacklistedTranslators)->pluck('translator_id')->all();

        $potentialTranslators = User::getPotentialUsers($translator_type, $joblanguage, $gender, $translator_level, $blacklistedIds);

        return $potentialTranslators;
    }

    private function mapJobTypeToTranslatorType($job_type)
    {
        switch ($job_type) {
            case 'paid':
                return 'professional';
            case 'rws':
                return 'rwstranslator';
            case 'unpaid':
                return 'volunteer';
            default:
                return 'professional'; // Default to professional if job type is not recognized
        }
    }

    private function getTranslatorLevels($certified)
    {
        $levels = [];

        if (empty($certified)) {
            $levels = ['Certified', 'Certified with specialisation in law', 'Certified with specialisation in health care', 'Layman', 'Read Translation courses'];
        } elseif ($certified == 'yes' || $certified == 'both') {
            $levels = ['Certified', 'Certified with specialisation in law', 'Certified with specialisation in health care'];
        } elseif ($certified == 'law' || $certified == 'n_law') {
            $levels = ['Certified with specialisation in law'];
        } elseif ($certified == 'health' || $certified == 'n_health') {
            $levels = ['Certified with specialisation in health care'];
        } elseif ($certified == 'normal' || $certified == 'both') {
            $levels = ['Layman', 'Read Translation courses'];
        }

        return $levels;
    }


    /**
     * @param $id
     * @param $data
     * @return mixed
     */
    public function updateJob($id, $data, $cuser)
    {
        $job = Job::find($id);
        $log_data = [];

        $current_translator = $this->getCurrentTranslator($job);
        $changeTranslatorResult = $this->changeTranslator($current_translator, $data, $job);
        if ($changeTranslatorResult['translatorChanged']) {
            $log_data[] = $changeTranslatorResult['log_data'];
        }

        $changeDueResult = $this->changeDue($job, $data);
        if ($changeDueResult['dateChanged']) {
            $log_data[] = $changeDueResult['log_data'];
        }

        $langChanged = $this->changeLanguage($job, $data);
        if ($langChanged) {
            $log_data[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id']),
            ];
        }

        $changeStatusResult = $this->changeStatus($job, $data, $changeTranslatorResult['translatorChanged']);
        if ($changeStatusResult['statusChanged']) {
            $log_data[] = $changeStatusResult['log_data'];
        }

        $job->admin_comments = $data['admin_comments'];
        $job->reference = $data['reference'];

        $this->logJobUpdate($cuser, $job, $log_data);

        $this->saveJobAndSendNotifications($job, $changeDueResult, $changeTranslatorResult, $langChanged);
    }

    private function logJobUpdate($cuser, $job, $log_data)
    {
        $this->logger->addInfo('USER #' . $cuser->id . '(' . $cuser->name . ')' . ' has been updated booking <a class="openjob" href="/admin/jobs/' . $job->id . '">#' . $job->id . '</a> with data:  ', $log_data);
    }

    private function saveJobAndSendNotifications($job, $changeDueResult, $changeTranslatorResult, $langChanged)
    {
        if ($job->due <= Carbon::now()) {
            $job->save();
        } else {
            $job->save();
            if ($changeDueResult['dateChanged']) {
                $this->sendChangedDateNotification($job, $changeDueResult['old_time']);
            }
            if ($changeTranslatorResult['translatorChanged']) {
                $this->sendChangedTranslatorNotification($job, $changeTranslatorResult['current_translator'], $changeTranslatorResult['new_translator']);
            }
            if ($langChanged) {
                $this->sendChangedLangNotification($job, $changeTranslatorResult['old_lang']);
            }
        }
    }


    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return array
     */
    private function changeStatus($job, $data, $changedTranslator)
    {
        $old_status = $job->status;
        $statusChanged = false;
        if ($old_status != $data['status']) {
            switch ($job->status) {
                case 'timedout':
                    $statusChanged = $this->changeTimedoutStatus($job, $data, $changedTranslator);
                    break;
                case 'completed':
                    $statusChanged = $this->changeCompletedStatus($job, $data);
                    break;
                case 'started':
                    $statusChanged = $this->changeStartedStatus($job, $data);
                    break;
                case 'pending':
                    $statusChanged = $this->changePendingStatus($job, $data, $changedTranslator);
                    break;
                case 'withdrawafter24':
                    $statusChanged = $this->changeWithdrawafter24Status($job, $data);
                    break;
                case 'assigned':
                    $statusChanged = $this->changeAssignedStatus($job, $data);
                    break;
                default:
                    $statusChanged = false;
                    break;
            }
            if ($statusChanged) {
                $log_data = [
                    'old_status' => $old_status,
                    'new_status' => $data['status']
                ];
                $statusChanged = true;
                return ['statusChanged' => $statusChanged, 'log_data' => $log_data];
            }
        }
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changeTimedoutStatus($job, $data, $changedTranslator)
    {
//        if (in_array($data['status'], ['pending', 'assigned']) && date('Y-m-d H:i:s') <= $job->due) {
        $old_status = $job->status;
        $job->status = $data['status'];
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];
        if ($data['status'] == 'pending') {
            $job->created_at = date('Y-m-d H:i:s');
            $job->emailsent = 0;
            $job->emailsenttovirpal = 0;
            $job->save();
            $job_data = $this->jobToData($job);

            $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);

            $this->sendNotificationTranslator($job, $job_data, '*');   // send Push all sutiable translators

            return true;
        } elseif ($changedTranslator) {
            $job->save();
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);
            return true;
        }

//        }
        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeCompletedStatus($job, $data)
    {
//        if (in_array($data['status'], ['withdrawnbefore24', 'withdrawafter24', 'timedout'])) {
        $job->status = $data['status'];
        if ($data['status'] == 'timedout') {
            if ($data['admin_comments'] == '') return false;
            $job->admin_comments = $data['admin_comments'];
        }
        $job->save();
        return true;
//        }
        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeStartedStatus($job, $data)
    {
//        if (in_array($data['status'], ['withdrawnbefore24', 'withdrawafter24', 'timedout', 'completed'])) {
        $job->status = $data['status'];
        if ($data['admin_comments'] == '') return false;
        $job->admin_comments = $data['admin_comments'];
        if ($data['status'] == 'completed') {
            $user = $job->user()->first();
            if ($data['sesion_time'] == '') return false;
            $interval = $data['sesion_time'];
            $diff = explode(':', $interval);
            $job->end_at = date('Y-m-d H:i:s');
            $job->session_time = $interval;
            $session_time = $diff[0] . ' tim ' . $diff[1] . ' min';
            if (!empty($job->user_email)) {
                $email = $job->user_email;
            } else {
                $email = $user->email;
            }
            $name = $user->name;
            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'faktura'
            ];

            $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

            $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

            $email = $user->user->email;
            $name = $user->user->name;
            $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'lön'
            ];
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

        }
        $job->save();
        return true;
//        }
        return false;
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changePendingStatus($job, $data, $changedTranslator)
    {
//        if (in_array($data['status'], ['withdrawnbefore24', 'withdrawafter24', 'timedout', 'assigned'])) {
        $job->status = $data['status'];
        if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;
        $job->admin_comments = $data['admin_comments'];
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];

        if ($data['status'] == 'assigned' && $changedTranslator) {

            $job->save();
            $job_data = $this->jobToData($job);

            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

            $translator = Job::getJobsAssignedTranslatorDetail($job);
            $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);

            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

            $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
            $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);
            return true;
        } else {
            $subject = 'Avbokning av bokningsnr: #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);
            $job->save();
            return true;
        }


//        }
        return false;
    }

    /*
     * TODO remove method and add service for notification
     * TEMP method
     * send session start remind notification
     */
    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    {

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
        $data = array();
        $data['notification_type'] = 'session_start_remind';
        $due_explode = explode(' ', $due);
        if ($job->customer_physical_type == 'yes')
            $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (på plats i ' . $job->town . ') kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );
        else
            $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (telefon) kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min.Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
            $this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
        }
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeWithdrawafter24Status($job, $data)
    {
        if (in_array($data['status'], ['timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '') return false;
            $job->admin_comments = $data['admin_comments'];
            $job->save();
            return true;
        }
        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeAssignedStatus($job, $data)
    {
        if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;
            $job->admin_comments = $data['admin_comments'];
            if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
                $user = $job->user()->first();

                if (!empty($job->user_email)) {
                    $email = $job->user_email;
                } else {
                    $email = $user->email;
                }
                $name = $user->name;
                $dataEmail = [
                    'user' => $user,
                    'job'  => $job
                ];

                $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
                $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

                $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

                $email = $user->user->email;
                $name = $user->user->name;
                $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
                $dataEmail = [
                    'user' => $user,
                    'job'  => $job
                ];
                $this->mailer->send($email, $name, $subject, 'emails.job-cancel-translator', $dataEmail);
            }
            $job->save();
            return true;
        }
        return false;
    }

    /**
     * @param $current_translator
     * @param $data
     * @param $job
     * @return array
     */
    private function changeTranslator($current_translator, $data, $job)
    {
        $translatorChanged = false;
        $log_data = [];

        if (!is_null($current_translator) || $this->isTranslatorChangeRequested($data)) {
            if ($this->isTranslatorChangeRequested($data)) {
                $new_translator = $this->getNewTranslator($data, $current_translator);
                $this->cancelCurrentTranslator($current_translator);
                $log_data[] = $this->createLogData($current_translator, $new_translator);
                $translatorChanged = true;
            }

            if ($translatorChanged) {
                return ['translatorChanged' => $translatorChanged, 'new_translator' => $new_translator, 'log_data' => $log_data];
            }
        }

        return ['translatorChanged' => $translatorChanged];
    }

    private function isTranslatorChangeRequested($data)
    {
        return (isset($data['translator']) && ($data['translator'] != 0 || $data['translator_email'] != ''));
    }

    private function getNewTranslator($data, $current_translator)
    {
        if ($data['translator_email'] != '') {
            $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
        }
        $new_translator = Translator::create(['user_id' => $data['translator']]);
        return $new_translator;
    }

    private function cancelCurrentTranslator($current_translator)
    {
        $current_translator->cancel_at = Carbon::now();
        $current_translator->save();
    }

    private function createLogData($current_translator, $new_translator)
    {
        return [
            'old_translator' => $current_translator->user->email,
            'new_translator' => $new_translator->user->email,
        ];
    }


    /**
     * @param $old_due
     * @param $new_due
     * @return array
     */
    private function changeDue($old_due, $new_due)
    {
        $dateChanged = false;
        if ($old_due != $new_due) {
            $log_data = [
                'old_due' => $old_due,
                'new_due' => $new_due
            ];
            $dateChanged = true;
            return ['dateChanged' => $dateChanged, 'log_data' => $log_data];
        }

        return ['dateChanged' => $dateChanged];

    }

    /**
     * @param $job
     * @param $current_translator
     * @param $new_translator
     */
    public function sendChangedTranslatorNotification($job, $current_translator, $new_translator)
    {
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag # ' . $job->id . ')';
        $data = [
            'user' => $user,
            'job'  => $job
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-customer', $data);
        if ($current_translator) {
            $user = $current_translator->user;
            $name = $user->name;
            $email = $user->email;
            $data['user'] = $user;

            $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-old-translator', $data);
        }

        $user = $new_translator->user;
        $name = $user->name;
        $email = $user->email;
        $data['user'] = $user;

        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-new-translator', $data);

    }

    /**
     * @param $job
     * @param $old_time
     */
    public function sendChangedDateNotification($job, $old_time)
    {
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_time' => $old_time
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-date', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $data = [
            'user'     => $translator,
            'job'      => $job,
            'old_time' => $old_time
        ];
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);

    }

    /**
     * @param $job
     * @param $old_lang
     */
    public function sendChangedLangNotification($job, $old_lang)
    {
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_lang' => $old_lang
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-lang', $data);
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * Function to send Job Expired Push Notification
     * @param $job
     * @param $user
     */
    public function sendExpiredNotification($job, $user)
    {
        $data = array();
        $data['notification_type'] = 'job_expired';
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = array(
            "en" => 'Tyvärr har ingen tolk accepterat er bokning: (' . $language . ', ' . $job->duration . 'min, ' . $job->due . '). Vänligen pröva boka om tiden.'
        );

        if ($this->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
        }
    }

    /**
     * Function to send the notification for sending the admin job cancel
     * @param $job_id
     */
    public function sendNotificationByAdminCancelJob($job_id)
    {
        $job = Job::findOrFail($job_id);
        $userMeta = $job->user->userMeta()->first();
        $data = $this->prepareNotificationData($job, $userMeta);

        $this->sendNotificationTranslator($job, $data, '*');
    }

    private function prepareNotificationData($job, $userMeta)
    {
        $data = [
            'job_id' => $job->id,
            'from_language_id' => $job->from_language_id,
            'immediate' => $job->immediate,
            'duration' => $job->duration,
            'status' => $job->status,
            'gender' => $job->gender,
            'certified' => $job->certified,
            'due' => $job->due,
            'job_type' => $job->job_type,
            'customer_phone_type' => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town' => $userMeta->city,
            'customer_type' => $userMeta->customer_type,
        ];

        $dueDate = explode(" ", $job->due);
        $data['due_date'] = $dueDate[0];
        $data['due_time'] = $dueDate[1];
        $data['job_for'] = $this->prepareJobFor($job);

        return $data;
    }

    private function prepareJobFor($job)
    {
        $jobFor = [];

        if ($job->gender != null) {
            $jobFor[] = ($job->gender == 'male') ? 'Man' : 'Kvinna';
        }

        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $jobFor[] = 'normal';
                $jobFor[] = 'certified';
            } elseif ($job->certified == 'yes') {
                $jobFor[] = 'certified';
            } else {
                $jobFor[] = $job->certified;
            }
        }

        return $jobFor;
    }


    /**
     * send session start remind notificatio
     * @param $user
     * @param $job
     * @param $language
     * @param $due
     * @param $duration
     */
    private function sendNotificationChangePending($user, $job, $language, $due, $duration)
    {
        $data = array();
        $data['notification_type'] = 'session_start_remind';
        if ($job->customer_physical_type == 'yes')
            $msg_text = array(
                "en" => 'Du har nu fått platstolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
            );
        else
            $msg_text = array(
                "en" => 'Du har nu fått telefontolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
            );

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
        }
    }

    /**
     * making user_tags string from users array for creating onesignal notifications
     * @param $users
     * @return string
     */
    private function getUserTagsStringFromArray($users)
    {
        $user_tags = "[";
        $first = true;
        foreach ($users as $oneUser) {
            if ($first) {
                $first = false;
            } else {
                $user_tags .= ',{"operator": "OR"},';
            }
            $user_tags .= '{"key": "email", "relation": "=", "value": "' . strtolower($oneUser->email) . '"}';
        }
        $user_tags .= ']';
        return $user_tags;
    }

    /**
     * @param $data
     * @param $user
     */
    public function acceptJob($data, $user)
    {
        $cuser = $user;
        $job = Job::findOrFail($data['job_id']);
        $response = $this->processJobAcceptance($cuser, $job);

        return $response;
    }

    private function processJobAcceptance($user, $job)
    {
        if (Job::isTranslatorAlreadyBooked($job->id, $user->id, $job->due)) {
            return ['status' => 'fail', 'message' => 'Du har redan en bokning den tiden! Bokningen är inte accepterad.'];
        }

        if ($job->status !== 'pending' || !Job::insertTranslatorJobRel($user->id, $job->id)) {
            return ['status' => 'fail', 'message' => 'Kunde inte acceptera jobbet.'];
        }

        $job->status = 'assigned';
        $job->save();
        $response = $this->sendAcceptanceEmail($job, $user);

        $jobs = $this->getPotentialJobs($user);

        return [
            'list' => json_encode(['jobs' => $jobs, 'job' => $job], true),
            'status' => 'success',
        ];
    }

    private function sendAcceptanceEmail($job, $user)
    {
        $email = $job->user_email ?: $user->email;
        $name = $user->name;
        $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
        $data = ['user' => $user, 'job' => $job];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);
    }


    /*Function to accept the job with the job id*/
    public function acceptJobWithId($job_id, $cuser)
    {
        $adminemail = config('app.admin_email');
        $adminSenderEmail = config('app.admin_sender_email');
        $job = Job::findOrFail($job_id);
        $response = array();

        if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
                $job->status = 'assigned';
                $job->save();
                $user = $job->user()->get()->first();
                $mailer = new AppMailer();

                if (!empty($job->user_email)) {
                    $email = $job->user_email;
                    $name = $user->name;
                } else {
                    $email = $user->email;
                    $name = $user->name;
                }
                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                $data = [
                    'user' => $user,
                    'job'  => $job
                ];
                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

                $data = array();
                $data['notification_type'] = 'job_accepted';
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = array(
                    "en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
                );
                if ($this->isNeedToSendPush($user->id)) {
                    $users_array = array($user);
                    $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
                }
                // Your Booking is accepted sucessfully
                $response['status'] = 'success';
                $response['list']['job'] = $job;
                $response['message'] = 'Du har nu accepterat och fått bokningen för ' . $language . 'tolk ' . $job->duration . 'min ' . $job->due;
            } else {
                // Booking already accepted by someone else
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $response['status'] = 'fail';
                $response['message'] = 'Denna ' . $language . 'tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning';
            }
        } else {
            // You already have a booking the time
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning';
        }
        return $response;
    }

    public function cancelJobAjax($data, $user)
    {
        $response = [];
        $cuser = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        $translator = Job::getJobsAssignedTranslatorDetail($job);

        if ($cuser->is('customer')) {
            $response = $this->processCustomerCancellation($job, $translator);
        } else {
            $response = $this->processTranslatorCancellation($job);
        }

        return $response;
    }

    private function processCustomerCancellation($job, $translator)
    {
        $response = ['jobstatus' => 'success'];
        $withdrawTimeDiff = $job->withdraw_at->diffInHours($job->due);

        if ($withdrawTimeDiff >= 24) {
            $job->status = 'withdrawbefore24';
        } else {
            $job->status = 'withdrawafter24';
        }
        $job->save();
        Event::fire(new JobWasCanceled($job));
        $response['status'] = 'success';

        if ($translator) {
            $this->sendCancellationPushToTranslator($job, $translator);
        }

        return $response;
    }

    private function processTranslatorCancellation($job)
    {
        $response = [];
        $hoursUntilJob = $job->due->diffInHours(Carbon::now());

        if ($hoursUntilJob > 24) {
            $customer = $job->user()->first();
            $response = $this->processCustomerCancellation($job, null);

            if ($customer) {
                $this->sendCancellationPushToCustomer($job, $customer);
            }
        } else {
            $response['status'] = 'fail';
            $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning över telefon. Tack!';
        }

        return $response;
    }

    private function sendCancellationPushToTranslator($job, $translator)
    {
        $data=[];
        $data = [
            'notification_type' => 'job_cancelled',
            'language' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
            'msg_text' => [
                "en" => 'Kunden har avbokat bokningen för ' . $data['language'] . 'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
            ]
        ];

        if ($this->isNeedToSendPush($translator->id)) {
            $this->sendPushNotificationToSpecificUsers([$translator], $job->id, $data, $data['msg_text'], $this->isNeedToDelayPush($translator->id));
        }
    }

    private function sendCancellationPushToCustomer($job, $customer)
    {
        $data = [];
        $data = [
            'notification_type' => 'job_cancelled',
            'language' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
            'msg_text' => [
                "en" => 'Er ' . $data['language'] . 'tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
            ]
        ];

        if ($this->isNeedToSendPush($customer->id)) {
            $this->sendPushNotificationToSpecificUsers([$customer], $job->id, $data, $data['msg_text'], $this->isNeedToDelayPush($customer->id));
        }

        $job->status = 'pending';
        $job->created_at = date('Y-m-d H:i:s');
        $job->will_expire_at = TeHelper::willExpireAt($job->due, date('Y-m-d H:i:s'));
        $job->save();
        $translator = $this->getPotentialTranslators($job->id);
        Job::deleteTranslatorJobRel($translator->id, $job->id);

        $data = $this->jobToData($job);

        $this->sendNotificationTranslator($job, $data, $translator->id);
    }


    /*Function to get the potential jobs for paid,rws,unpaid translators*/
    public function getPotentialJobs($cuser)
    {
        $cuser_meta = $cuser->userMeta;
        $job_type = 'unpaid';
        $translator_type = $cuser_meta->translator_type;
        if ($translator_type == 'professional')
            $job_type = 'paid';   /*show all jobs for professionals.*/
        else if ($translator_type == 'rwstranslator')
            $job_type = 'rws';  /* for rwstranslator only show rws jobs. */
        else if ($translator_type == 'volunteer')
            $job_type = 'unpaid';  /* for volunteers only show unpaid jobs. */

        $languages = UserLanguages::where('user_id', '=', $cuser->id)->get();
        $userlanguage = collect($languages)->pluck('lang_id')->all();
        $gender = $cuser_meta->gender;
        $translator_level = $cuser_meta->translator_level;
        /*Call the town function for checking if the job physical, then translators in one town can get job*/
        $job_ids = Job::getJobs($cuser->id, $job_type, 'pending', $userlanguage, $gender, $translator_level);
        foreach ($job_ids as $k => $job) {
            $jobuserid = $job->user_id;
            $job->specific_job = Job::assignedToPaticularTranslator($cuser->id, $job->id);
            $job->check_particular_job = Job::checkParticularJob($cuser->id, $job);
            $checktown = Job::checkTowns($jobuserid, $cuser->id);

            if($job->specific_job == 'SpecificJob')
                if ($job->check_particular_job == 'userCanNotAcceptJob')
                unset($job_ids[$k]);

            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checktown == false) {
                unset($job_ids[$k]);
            }
        }
//        $jobs = TeHelper::convertJobIdsInObjs($job_ids);
        return $job_ids;
    }

    public function endJob($post_data)
    {
        $completeddate = date('Y-m-d H:i:s');
        $jobid = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($jobid);

        if($job_detail->status != 'started')
            return ['status' => 'success'];

        $duedate = $job_detail->due;
        $start = date_create($duedate);
        $end = date_create($completeddate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job = $job_detail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'completed';
        $job->session_time = $interval;

        $user = $job->user()->get()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $session_explode = explode(':', $job->session_time);
        $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'faktura'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $job->save();

        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();

        Event::fire(new SessionEnded($job, ($post_data['user_id'] == $job->user_id) ? $tr->user_id : $job->user_id));

        $user = $tr->user()->first();
        $email = $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'lön'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $tr->completed_at = $completeddate;
        $tr->completed_by = $post_data['user_id'];
        $tr->save();
        $response['status'] = 'success';
        return $response;
    }


    public function customerNotCall($post_data)
    {
        $completeddate = date('Y-m-d H:i:s');
        $jobid = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($jobid);
        $duedate = $job_detail->due;
        $start = date_create($duedate);
        $end = date_create($completeddate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job = $job_detail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'not_carried_out_customer';

        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();
        $tr->completed_at = $completeddate;
        $tr->completed_by = $tr->user_id;
        $job->save();
        $tr->save();
        $response['status'] = 'success';
        return $response;
    }

    public function getAll(Request $request, $limit = null)
    {
        $requestdata = $request->all();
        $cuser = $request->__authenticatedUser;
        $consumer_type = $cuser->consumer_type;
        $allJobs = Job::query();

        if ($cuser && $cuser->user_type == env('SUPERADMIN_ROLE_ID')) {
            $allJobs = $this->applyFiltersForSuperAdmin($allJobs, $requestdata);

        } else {
            $allJobs = $this->applyFiltersForNonSuperAdmin($allJobs, $consumer_type, $requestdata);
        }

        if ($limit == 'all') {
            $allJobs = $allJobs->get();
        } else {
            $allJobs = $allJobs->paginate(15);
        }

        return $allJobs;
    }

    private function applyFiltersForSuperAdmin($allJobs, $requestdata)
    {
        $allJobs = $allJobs->filter(function ($query) use ($requestdata) {
            if (isset($requestdata['feedback']) && $requestdata['feedback'] != 'false') {
                $query->where('ignore_feedback', '0');
                $query->whereHas('feedback', function ($q) {
                    $q->where('rating', '<=', '3');
                });

                if (isset($requestdata['count']) && $requestdata['count'] != 'false') {
                    return ['count' => $query->count()];
                }
            }

            // Apply other filters as needed
            // ...

            return $query;
        });

        return $allJobs;
    }

    private function applyFiltersForNonSuperAdmin($allJobs, $consumer_type, $requestdata)
    {
        if ($consumer_type == 'RWS') {
            $allJobs->where('job_type', '=', 'rws');
        } else {
            $allJobs->where('job_type', '=', 'unpaid');
        }

        $allJobs = $allJobs->filter(function ($query) use ($requestdata) {
            if (isset($requestdata['feedback']) && $requestdata['feedback'] != 'false') {
                $query->where('ignore_feedback', '0');
                $query->whereHas('feedback', function ($q) {
                    $q->where('rating', '<=', '3');
                });

                if (isset($requestdata['count']) && $requestdata['count'] != 'false') {
                    return ['count' => $query->count()];
                }
            }

            // Apply other filters as needed
            // ...

            return $query;
        });

        return $allJobs;
    }


    public function alerts()
    {
        $jobs = Job::all();
        $sesJobs = [];
        $jobId = [];
        $diff = [];
        $i = 0;

        foreach ($jobs as $job) {
            $sessionTime = explode(':', $job->session_time);
            if (count($sessionTime) >= 3) {
                $diff[$i] = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);

                if ($diff[$i] >= $job->duration) {
                    if ($diff[$i] >= $job->duration * 2) {
                        $sesJobs [$i] = $job;
                    }
                }
                $i++;
            }
        }

        foreach ($sesJobs as $job) {
            $jobId [] = $job->id;
        }

        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
        $all_translators = DB::table('users')->where('user_type', '2')->lists('email');

        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');


        if ($cuser && $cuser->is('superadmin')) {
            $allJobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')->whereIn('jobs.id', $jobId);
            if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
                $allJobs->whereIn('jobs.from_language_id', $requestdata['lang'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.from_language_id', '=', $requestdata['lang']);*/
            }
            if (isset($requestdata['status']) && $requestdata['status'] != '') {
                $allJobs->whereIn('jobs.status', $requestdata['status'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.status', '=', $requestdata['status']);*/
            }
            if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
                if ($user) {
                    $allJobs->where('jobs.user_id', '=', $user->id)
                        ->where('jobs.ignore', 0);
                }
            }
            if (isset($requestdata['translator_email']) && $requestdata['translator_email'] != '') {
                $user = DB::table('users')->where('email', $requestdata['translator_email'])->first();
                if ($user) {
                    $allJobIDs = DB::table('translator_job_rel')->where('user_id', $user->id)->lists('job_id');
                    $allJobs->whereIn('jobs.id', $allJobIDs)
                        ->where('jobs.ignore', 0);
                }
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.created_at', '>=', $requestdata["from"])
                        ->where('jobs.ignore', 0);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.created_at', '<=', $to)
                        ->where('jobs.ignore', 0);
                }
                $allJobs->orderBy('jobs.created_at', 'desc');
            }
            if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
                if (isset($requestdata['from']) && $requestdata['from'] != "") {
                    $allJobs->where('jobs.due', '>=', $requestdata["from"])
                        ->where('jobs.ignore', 0);
                }
                if (isset($requestdata['to']) && $requestdata['to'] != "") {
                    $to = $requestdata["to"] . " 23:59:00";
                    $allJobs->where('jobs.due', '<=', $to)
                        ->where('jobs.ignore', 0);
                }
                $allJobs->orderBy('jobs.due', 'desc');
            }

            if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
                $allJobs->whereIn('jobs.job_type', $requestdata['job_type'])
                    ->where('jobs.ignore', 0);
                /*$allJobs->where('jobs.job_type', '=', $requestdata['job_type']);*/
            }
            $allJobs->select('jobs.*', 'languages.language')
                ->where('jobs.ignore', 0)
                ->whereIn('jobs.id', $jobId);

            $allJobs->orderBy('jobs.created_at', 'desc');
            $allJobs = $allJobs->paginate(15);
        }

        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $requestdata];
    }

    public function userLoginFailed()
    {
        $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);

        return ['throttles' => $throttles];
    }

    public function bookingExpireNoAccepted()
    {
        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
        $all_translators = DB::table('users')->where('user_type', '2')->lists('email');

        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');

        if ($cuser && ($cuser->is('superadmin') || $cuser->is('admin'))) {
            $allJobs = $this->applyFiltersForAdmin($requestdata);

        } else {
            $allJobs = $this->applyFiltersForNonAdmin($requestdata, $cuser, $consumer_type);
        }

        return ['allJobs' => $allJobs, 'languages' => $languages, 'all_customers' => $all_customers, 'all_translators' => $all_translators, 'requestdata' => $requestdata];
    }

    private function applyFiltersForAdmin($requestdata)
    {
        $allJobs = Job::join('languages', 'jobs.from_language_id', '=', 'languages.id')
            ->where('jobs.ignore_expired', 0)
            ->where('jobs.status', 'pending')
            ->where('jobs.ignore_expired', 0)
            ->where('jobs.due', '>=', Carbon::now());

        $allJobs = $this->applyCommonFilters($allJobs, $requestdata);

        return $allJobs->paginate(15);
    }

    private function applyFiltersForNonAdmin($requestdata, $cuser, $consumer_type)
    {
        $allJobs = DB::table('jobs')
            ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
            ->where('jobs.ignore_expired', 0);

        if ($cuser->is('customer')) {
            $allJobs = $allJobs->where('jobs.status', 'pending')
                ->where('jobs.ignore_expired', 0)
                ->where('jobs.due', '>=', Carbon::now());
        }

        $allJobs = $this->applyCommonFilters($allJobs, $requestdata);

        return $allJobs->paginate(15);
    }

    private function applyCommonFilters($allJobs, $requestdata)
    {
        // Apply common filters
        if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
            $allJobs->whereIn('jobs.from_language_id', $requestdata['lang']);
        }

        if (isset($requestdata['status']) && $requestdata['status'] != '') {
            $allJobs->whereIn('jobs.status', $requestdata['status']);
        }

        if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
            $user = DB::table('users')->where('email', $requestdata['customer_email'])->first();
            if ($user) {
                $allJobs->where('jobs.user_id', '=', $user->id);
            }
        }

        // Apply other filters as needed
        // ...

        return $allJobs;
    }


    public function ignoreExpiring($id)
    {
        $job = Job::find($id);
        $job->ignore = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreExpired($id)
    {
        $job = Job::find($id);
        $job->ignore_expired = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreThrottle($id)
    {
        $throttle = Throttles::find($id);
        $throttle->ignore = 1;
        $throttle->save();
        return ['success', 'Changes saved'];
    }

    public function reopen($request)
    {
        $jobid = $request['jobid'];
        $userid = $request['userid'];

        $job = Job::find($jobid);
        $job = $job->toArray();

        $data = array();
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['will_expire_at'] = TeHelper::willExpireAt($job['due'], $data['created_at']);
        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['user_id'] = $userid;
        $data['job_id'] = $jobid;
        $data['cancel_at'] = Carbon::now();

        $datareopen = array();
        $datareopen['status'] = 'pending';
        $datareopen['created_at'] = Carbon::now();
        $datareopen['will_expire_at'] = TeHelper::willExpireAt($job['due'], $datareopen['created_at']);
        //$datareopen['updated_at'] = date('Y-m-d H:i:s');

//        $this->logger->addInfo('USER #' . Auth::user()->id . ' reopen booking #: ' . $jobid);

        if ($job['status'] != 'timedout') {
            $affectedRows = Job::where('id', '=', $jobid)->update($datareopen);
            $new_jobid = $jobid;
        } else {
            $job['status'] = 'pending';
            $job['created_at'] = Carbon::now();
            $job['updated_at'] = Carbon::now();
            $job['will_expire_at'] = TeHelper::willExpireAt($job['due'], date('Y-m-d H:i:s'));
            $job['updated_at'] = date('Y-m-d H:i:s');
            $job['cust_16_hour_email'] = 0;
            $job['cust_48_hour_email'] = 0;
            $job['admin_comments'] = 'This booking is a reopening of booking #' . $jobid;
            //$job[0]['user_email'] = $user_email;
            $affectedRows = Job::create($job);
            $new_jobid = $affectedRows['id'];
        }
        //$result = DB::table('translator_job_rel')->insertGetId($data);
        Translator::where('job_id', $jobid)->where('cancel_at', NULL)->update(['cancel_at' => $data['cancel_at']]);
        $Translator = Translator::create($data);
        if (isset($affectedRows)) {
            $this->sendNotificationByAdminCancelJob($new_jobid);
            return ["Tolk cancelled!"];
        } else {
            return ["Please try again!"];
        }
    }

    /**
     * Convert number of minutes to hour and minute variant
     * @param  int $time
     * @param  string $format
     * @return string
     */
    private function convertToHoursMins($time, $format = '%02dh %02dmin')
    {
        if ($time < 60) {
            return $time . 'min';
        } else if ($time == 60) {
            return '1h';
        }

        $hours = floor($time / 60);
        $minutes = ($time % 60);
        
        return sprintf($format, $hours, $minutes);
    }

}
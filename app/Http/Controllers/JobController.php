<?php

namespace App\Http\Controllers;

use App\Models\Job;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use App\Models\JobSeeker;
use App\Models\User;

class JobController extends Controller
{
   public function index(Request $request)
    {
        $perPage = max(1, (int) $request->query('per_page', 20));

        // Static lookups (1x each)
        $benefitsList  = DB::table('job_benefits')->select('id', 'name')->orderBy('name')->get();
        $categories    = DB::table('categories')->select('id', 'name')->orderBy('name')->get();
        $employersList = DB::table('employers')->select('id', 'company_name')->orderBy('company_name')->get();

        $query = Job::query()
            ->select([
                'jobs.*',
                'employers.company_name as company_name',
                'employers.website as employer_website',
                'categories.name as category_name',
            ])
            ->leftJoin('employers', 'employers.id', '=', 'jobs.employer_id')
            ->leftJoin('categories', 'categories.id', '=', 'jobs.category_id')
            ->where('jobs.status', 'published');

        /** ----------------- Filters ----------------- */

        // EXP level via REGEXP (this is inherently slow; only run if provided)
        if ($expRaw = $request->input('experiencelevel')) {
            $label   = Str::lower(trim($expRaw));
            $pattern = null;
            if (Str::startsWith($label, '0-1')) {
                $pattern = implode('|', [
                    '\b0-1\s*years?\b',
                    '\b(?:0|zero|1|one)\s*(?:\+?\s*)?(?:years?|yrs?)\b',
                    '\bno\s+experience\b',
                    '\bentry[-\s]?level\b',
                    '\bfresh(?:er)?\b',
                ]);
            } elseif (Str::startsWith($label, '2+')) {
                $pattern = implode('|', [
                    '\b(?:(?:[2-9]|[1-9][0-9]+))\s*\+?\s*(?:years?|yrs?)\b',
                    '\b(?:two|three|four|five|six|seven|eight|nine|ten)\s*\+?\s*(?:years?|yrs?)\b',
                    '\b(?:at\s+least|min(?:imum)?)\s*(?:2|two)\s*(?:years?|yrs?)\b',
                ]);
            } elseif (Str::startsWith($label, '3+')) {
                $pattern = implode('|', [
                    '\b(?:(?:[3-9]|[1-9][0-9]+))\s*\+?\s*(?:years?|yrs?)\b',
                    '\b(?:three|four|five|six|seven|eight|nine|ten)\s*\+?\s*(?:years?|yrs?)\b',
                    '\b(?:at\s+least|min(?:imum)?)\s*(?:3|three)\s*(?:years?|yrs?)\b',
                ]);
            } elseif (Str::startsWith($label, '5+')) {
                $pattern = implode('|', [
                    '\b(?:(?:[5-9]|[1-9][0-9]+))\s*\+?\s*(?:years?|yrs?)\b',
                    '\b(?:five|six|seven|eight|nine|ten)\s*\+?\s*(?:years?|yrs?)\b',
                    '\b(?:at\s+least|min(?:imum)?)\s*(?:5|five)\s*(?:years?|yrs?)\b',
                ]);
            } elseif (Str::startsWith($label, '10+')) {
                $pattern = implode('|', [
                    '\b(?:(?:1[0-9]|[2-9][0-9]+))\s*\+?\s*(?:years?|yrs?)\b',
                    '\b(?:ten|eleven|twelve)\s*\+?\s*(?:years?|yrs?)\b',
                    '\b(?:at\s+least|min(?:imum)?)\s*(?:10|ten)\s*(?:years?|yrs?)\b',
                ]);
            }
            if ($pattern) {
                $query->whereRaw('LOWER(jobs.description) REGEXP ?', [$pattern]);
            }
        }

        // search (TIP: replace with FULLTEXT for speed; see notes)
        if ($search = $request->string('search')->toString()) {
            $like = '%' . $search . '%';
            $query->where(function ($q) use ($like) {
                $q->where('jobs.title', 'like', $like)
                ->orWhere('jobs.description', 'like', $like);
            });
        }

        if ($jobType = $request->string('jobtypes')->toString()) {
            $query->where('jobs.job_type', 'like', "%$jobType%");
        }

        if (($benefitId = $request->integer('benefits')) > 0) {
            $query->whereExists(function ($sub) use ($benefitId) {
                $sub->selectRaw(1)
                    ->from('job_benefit_job as jb')
                    ->whereColumn('jb.job_id', 'jobs.id')
                    ->where('jb.job_benefit_id', $benefitId);
            });
        }

        if (($categoryId = $request->integer('category')) > 0) {
            $query->where('jobs.category_id', $categoryId);
        }

        // countries
        $countries = [];
        if ($c = $request->string('country')->toString()) { $countries[] = $c; }
        $countriesInput = $request->input('countries', []);
        if (is_string($countriesInput)) {
            $countriesInput = array_filter(array_map('trim', explode(',', $countriesInput)));
        }
        $countries = array_merge($countries, is_array($countriesInput) ? $countriesInput : []);
        if (!empty($countries)) {
            $countriesUpper = array_map('strtoupper', $countries);
            $query->where(function ($q) use ($countriesUpper, $countries) {
                $q->whereIn('jobs.country_code', $countriesUpper)
                ->orWhereIn('jobs.country_name', $countries);
            });
        }
        // Salary filtering (columns are DECIMAL dollars, not "k")
        if (($salary = $request->query('salary')) && is_string($salary)) {
            $query->where(function ($q) use ($salary) {
                $parsed = $this->parseSalaryRange($salary); // returns ['min' => ?int, 'max' => ?int] in DOLLARS
                if (!$parsed) return;

                $min = $parsed['min']; // dollars
                $max = $parsed['max']; // dollars

                if ($min !== null && $max !== null) {
                    // BETWEEN/overlap semantics:
                    // include jobs whose range intersects [min, max]
                    $q->where(function ($qq) use ($min, $max) {
                        $qq
                        // both bounds known: overlap if job_min <= max AND job_max >= min
                        ->where(function ($c) use ($min, $max) {
                            $c->whereNotNull('jobs.pay_min')
                            ->whereNotNull('jobs.pay_max')
                            ->where('jobs.pay_min', '<=', $max)
                            ->where('jobs.pay_max', '>=', $min);
                        })
                        // only job_min known: keep only if that min itself lies inside [min, max]
                        ->orWhere(function ($c) use ($min, $max) {
                            $c->whereNotNull('jobs.pay_min')
                            ->whereNull('jobs.pay_max')
                            ->whereBetween('jobs.pay_min', [$min, $max]);
                        })
                        // only job_max known: keep only if that max itself lies inside [min, max]
                        ->orWhere(function ($c) use ($min, $max) {
                            $c->whereNull('jobs.pay_min')
                            ->whereNotNull('jobs.pay_max')
                            ->whereBetween('jobs.pay_max', [$min, $max]);
                        });
                    });
                } elseif ($min !== null) {
                    // "180k+" → any job whose range reaches or exceeds min
                    $q->where(function ($qq) use ($min) {
                        $qq->where(function ($c) use ($min) {
                                $c->whereNotNull('jobs.pay_min')->where('jobs.pay_min', '>=', $min);
                            })
                        ->orWhere(function ($c) use ($min) {
                                $c->whereNotNull('jobs.pay_max')->where('jobs.pay_max', '>=', $min);
                            });
                    });
                } elseif ($max !== null) {
                    // "up to 80k" → any job whose range is at or below max
                    $q->where(function ($qq) use ($max) {
                        $qq->where(function ($c) use ($max) {
                                $c->whereNotNull('jobs.pay_min')->where('jobs.pay_min', '<=', $max);
                            })
                        ->orWhere(function ($c) use ($max) {
                                $c->whereNotNull('jobs.pay_max')->where('jobs.pay_max', '<=', $max);
                            });
                    });
                }
            });

            // If you only support USD searches, uncomment this:
            // $query->where('jobs.currency', 'USD');
        }


        // skills by slug
        $skillSlugs = $request->input('skills', []);
        if (is_string($skillSlugs)) {
            $skillSlugs = array_filter(array_map('trim', explode(',', $skillSlugs)));
        }
        if (!empty($skillSlugs)) {
            $slugged = array_map(fn($v) => Str::slug($v), $skillSlugs);
            $names   = $skillSlugs;
            $query->whereIn('jobs.id', function ($sub) use ($slugged, $names) {
                $sub->select('job_skill.job_id')
                    ->from('job_skill')
                    ->join('skills', 'skills.id', '=', 'job_skill.skill_id')
                    ->where(function ($qq) use ($slugged, $names) {
                        $qq->whereIn('skills.slug', $slugged)
                        ->orWhereIn('skills.name', $names);
                    });
            });
        }

        // date posted
        if (($datePostedRaw = $request->input('dateposted')) !== null
            && $datePostedRaw !== '' && Str::lower($datePostedRaw) !== 'any') {
            $label = Str::of($datePostedRaw)->lower()->trim();
            $from = match ($label) {
                'last 24 hours', '24h', '1d', '1 day' => now()->subDay(),
                'last 7 days', '7d'                   => now()->subDays(7),
                'last 30 days', '30d'                 => now()->subDays(30),
                'last 2 months', '2m', 'last two months' => now()->subMonthsNoOverflow(2),
                default => null,
            };
            if (!$from && preg_match('/last\s+(\d+)\s+(day|days|month|months|hour|hours)/i', (string) $datePostedRaw, $m)) {
                $n = (int) $m[1];
                $unit = Str::lower($m[2]);
                $from = match ($unit) {
                    'hour', 'hours'   => Carbon::now()->subHours($n),
                    'day', 'days'     => Carbon::now()->subDays($n),
                    'month', 'months' => Carbon::now()->subMonthsNoOverflow($n),
                    default           => null,
                };
            }
            if ($from) {
                $query->where('jobs.posted_at', '>=', $from);
            }
        }

        if (($employerId = $request->integer('company')) > 0) {
            $query->where('jobs.employer_id', $employerId);
        }

        // Sorting
        $sort = $request->string('sort')->toString();
        $query->orderBy('jobs.posted_at', $sort === 'oldest' ? 'asc' : 'desc');

        /** --------------- Execute main query --------------- */
        $paginator = $query->paginate($perPage);

        // User context (batch) — OK
        $userId = Auth::guard('sanctum')->id();
        $jobIds = collect($paginator->items())->pluck('id')->all();

        $appByJob = collect();
        if ($userId && $jobIds) {
            $appByJob = DB::table('job_applications as ja')
                ->select('ja.job_id', 'ja.job_seeker_id', 'ja.created_at')
                ->where('ja.job_seeker_id', $userId)
                ->whereIn('ja.job_id', $jobIds)
                ->orderBy('ja.created_at', 'desc')
                ->get()
                ->groupBy('job_id')
                ->map->first();
        }

        $savedByJob = collect();
        if ($userId && $jobIds) {
            $seekerId = JobSeeker::where('user_id', $userId)->value('id');
            if ($seekerId) {
                $savedByJob = DB::table('saved_jobs as sj')
                    ->select('sj.job_id')
                    ->where('sj.job_seeker_id', $seekerId)
                    ->whereIn('sj.job_id', $jobIds)
                    ->get()
                    ->keyBy('job_id');
            }
        }

        // Benefits for all jobs in ONE query (no per-row round trips)
        $benefitsByJob = collect();
        if ($jobIds) {
            $benefitsByJob = DB::table('job_benefit_job as jb')
                ->join('job_benefits as b', 'b.id', '=', 'jb.job_benefit_id')
                ->whereIn('jb.job_id', $jobIds)
                ->orderBy('b.name')
                ->get(['jb.job_id', 'b.name'])
                ->groupBy('job_id')
                ->map(fn($rows) => $rows->pluck('name')->all());
        }

        /** --------------- Shape response (no N+1) --------------- */
        $data = collect($paginator->items())->map(function ($row) use ($appByJob, $savedByJob, $benefitsByJob) {
            // $row is a Job model with joined columns already available
            $postedAt = $row->posted_at ?: $row->created_at;
            $closedAt = $row->closed_at;
            $isNew    = $postedAt ? Carbon::parse($postedAt)->greaterThanOrEqualTo(now()->subDays(7)) : false;
            $isFeat   = ($row->pay_max && $row->pay_max >= 150000) || ($isNew && $row->job_type === 'full_time');

            $tags = [];
            if ($isFeat) $tags[] = 'Featured';
            if ($row->job_type) $tags[] = self::humanizeJobType($row->job_type);
            if ($row->location_type === 'remote') $tags[] = 'Remote';

            $salaryRange = null;
            if ($row->pay_min || $row->pay_max) {
                $fmt = fn ($v) => is_null($v) ? null : ('$' . number_format((float)$v / 1000, 0) . 'k');
                $min = $fmt($row->pay_min);
                $max = $fmt($row->pay_max);
                $salaryRange = $min && $max ? "$min - $max" : ($min ?: $max);
            }

            $company = [
                'name'     => $row->company_name ?? 'Unknown Company',
                'location' => $row->location_type === 'remote'
                    ? 'Remote'
                    : (trim(implode(', ', array_filter([$row->city, $row->state_province, $row->country_code]))) ?: $row->country_code),
                'website'  => $row->employer_website,
            ];

            $app   = $appByJob->get($row->id);
            $saved = $savedByJob->get($row->id);
            $benef = $benefitsByJob->get($row->id, []);

            return [
                'id'              => (int) $row->id,
                'title'           => $row->title,
                'company'         => $company,
                'vacancies'       => $row->vacancies,
                'job_type'        => self::humanizeJobType($row->job_type),
                'salary_range'    => $salaryRange,
                'tags'            => $tags,
                'is_featured'     => (bool) $isFeat,
                'is_new'          => (bool) $isNew,
                'posted_at'       => optional($postedAt)->toISOString() ?? null,
                'closed_at'       => optional($closedAt)->toISOString() ?? null,
                'description'     => (string) $row->description,
                'overview'        => $this->generateOverviewFromDescription($row->description),
                'requirements'    => $this->generateRequirementsFromDescription($row->description),
                'responsibilities'=> $this->generateResponsibilitiesFromDescription($row->description),
                'benefits'        => $benef,
                'application_link'=> $company['website'] ?: null,
                'has_applied'     => (bool) $app,
                'is_saved'        => (bool) $saved,
            ];
        })->all();

        return response()->json([
            'data'       => $data,
            'benefits'   => $benefitsList,
            'categories' => $categories,
            'employers'  => $employersList,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'total_pages'  => $paginator->lastPage(),
                'total_jobs'   => $paginator->total(),
            ],
        ]);
    }


   public function show(Job $job)
    {
        // Eager-load related data
        $job->load(['employer','preferences','screeningQuestions']);

        // Company details
        $company = [
            'name' => optional($job->employer)->company_name,
            'location' => $job->location_type === 'remote'
                ? 'Remote'
                : trim(implode(', ', array_filter([$job->city, $job->state_province, $job->country_code]))),
            'website' => optional($job->employer)->website,
        ];

        // Benefits (names)
        $benefits = DB::table('job_benefit_job')
            ->join('job_benefits', 'job_benefits.id', '=', 'job_benefit_job.job_benefit_id')
            ->where('job_benefit_job.job_id', $job->id)
            ->pluck('job_benefits.name')
            ->all();

        $postedAt = $job->posted_at ?: $job->created_at;
        $closedAt = $job->closed_at;
        $isNew = $postedAt ? Carbon::parse($postedAt)->greaterThanOrEqualTo(now()->subDays(7)) : false;
        $isFeatured = ($job->pay_max && $job->pay_max >= 150000) || ($isNew && $job->job_type === 'full_time');

        $tags = [];
        if ($isFeatured) { $tags[] = 'Featured'; }
        if ($job->job_type) { $tags[] = self::humanizeJobType($job->job_type); }
        if ($job->location_type === 'remote') { $tags[] = 'Remote'; }

        $salaryRange = null;
        if ($job->pay_min || $job->pay_max) {
            $fmt = fn ($v) => is_null($v) ? null : ('$'.number_format((float)$v/1000, 0).'k');
            $min = $fmt($job->pay_min);
            $max = $fmt($job->pay_max);
            $salaryRange = $min && $max ? "$min - $max" : ($min ?: $max);
        }

        // ---> New bits (keep consistent with index):
        $userId = Auth::guard('sanctum')->id();
        $app = false;
        $saved = false;

        if ($userId) {
            // If your job_applications table stores job_seeker_id = USER id, this matches your index() logic.
            // If it actually stores the seeker PK, switch $userId to $seekerId below.
            $app = DB::table('job_applications as ja')
                ->where('ja.job_id', $job->id)
                ->where('ja.job_seeker_id', $userId)
                ->exists();

            $seekerId = JobSeeker::where('user_id', $userId)->value('id');
            
            if ($seekerId) {
                $saved = DB::table('saved_jobs as sj')
                    ->where('sj.job_seeker_id', $seekerId)
                    ->where('sj.job_id', $job->id)
                    ->exists();
            }
        }
        // <--- end new bits

        $response = [
            'id' => (int) $job->id,
            'title' => $job->title,
            'company' => $company,
            'vacancies' => $job->vacancies,                       // NEW
            'job_type' => self::humanizeJobType($job->job_type),
            'salary_range' => $salaryRange,
            'tags' => $tags,
            'is_featured' => (bool) $isFeatured,
            'is_new' => (bool) $isNew,
            'posted_at' => optional($postedAt)->toISOString() ?? null,
            'closed_at' => optional($closedAt)->toISOString() ?? null,  // NEW
            'description' => (string) $job->description,
            'overview' => $this->generateOverviewFromDescription($job->description),
            'requirements' => $this->generateRequirementsFromDescription($job->description),
            'responsibilities' => $this->generateResponsibilitiesFromDescription($job->description),
            'benefits' => $benefits,
            'application_link' => $company['website'] ?: null,
            'has_applied' => (bool) $app,                         // NEW
            'is_saved' => (bool) $saved,                          // NEW
            
        ];

        return response()->json($response);
    }

    private static function humanizeJobType(?string $jobType): string
    {
        if (!$jobType) { return ''; }
        return match ($jobType) {
            'full_time' => 'Full-Time',
            'part_time' => 'Part-Time',
            'temporary' => 'Temporary',
            'contract' => 'Contract',
            'internship' => 'Internship',
            'fresher' => 'Fresher',
            default => ucfirst(str_replace('_',' ', $jobType)),
        };
    }

    private static function composeSalaryRange(?string $visibility, $min, $max, ?string $currency): ?string
    {
        if (!$visibility) { return null; }
        $fmt = function ($v) use ($currency) {
            if (is_null($v)) { return null; }
            $prefix = ($currency ?? 'USD') === 'USD' ? '$' : '';
            return $prefix.number_format((float)$v/1000, 0).'k';
        };
        $minF = $fmt($min);
        $maxF = $fmt($max);

        return match ($visibility) {
            'range' => $minF && $maxF ? "$minF - $maxF" : ($minF ?: $maxF),
            'exact' => $maxF ?: $minF,
            'starting_at' => $minF ? ($minF.'+') : null,
            default => null,
        };
    }

    private function generateOverviewFromDescription(?string $description): string
    {
        if (!$description) { return ''; }
        // Take first 2-3 sentences as overview
        $sentences = preg_split('/\.(\s+|$)/', $description, -1, PREG_SPLIT_NO_EMPTY);
        $overviewSentences = array_slice($sentences, 0, 3);
        return implode('. ', $overviewSentences) . '.';
    }

    private function generateRequirementsFromDescription(?string $description): array
    {
        if (!$description) { return []; }
        // Heuristic: split into bullet-like sentences
        $sentences = preg_split('/\.(\s+|$)/', $description, -1, PREG_SPLIT_NO_EMPTY);
        return array_map(fn ($s) => trim($s), array_slice($sentences, 0, 5));
    }

    private function generateResponsibilitiesFromDescription(?string $description): array
    {
        if (!$description) { return []; }
        $sentences = preg_split('/\.(\s+|$)/', $description, -1, PREG_SPLIT_NO_EMPTY);
        return array_map(fn ($s) => trim($s), array_slice($sentences, 5, 5));
    }

    /**
     * Parse salary range from frontend format like "$50k - $80k", "$180k+"
     */

   protected function parseSalaryRange(string $label): ?array
    {
        $s = strtolower($label);
        $s = str_replace(['$', ','], '', $s);
        $s = preg_replace('/\s+/', ' ', trim($s));

        if (preg_match('/^(\d+)\s*k\s*-\s*(\d+)\s*k$/', $s, $m)) {
            return ['min' => (int)$m[1] * 1000, 'max' => (int)$m[2] * 1000];
        }
        if (preg_match('/^(\d+)\s*k\s*\+$/', $s, $m)) {
            return ['min' => (int)$m[1] * 1000, 'max' => null];
        }
        if (preg_match('/^(?:up to|<=?|≤)\s*(\d+)\s*k$/', $s, $m)) {
            return ['min' => null, 'max' => (int)$m[1] * 1000];
        }
        if (preg_match('/^(\d+)\s*k$/', $s, $m)) {
            $v = (int)$m[1] * 1000;
            return ['min' => $v, 'max' => $v];
        }
        return null;
    }

    public function statsHero(Request $request)
    {
        // By default count only published jobs; override with ?published=0 to count all
        $onlyPublished = filter_var($request->query('published', '1'), FILTER_VALIDATE_BOOLEAN);

        $jobQuery = Job::query();
        if ($onlyPublished) {
            $jobQuery->where('status', 'published');
        }

        $totalJobs       = $jobQuery->count();
        $totalSeekers    = User::where('role', 'seeker')->count();
        $totalEmployers  = User::where('role', 'employer')->count();

        // Distinct employers that have at least one (published) job — "Companies Hiring"
        $companiesHiring = Job::when($onlyPublished, fn($q) => $q->where('status', 'published'))
            ->whereNotNull('employer_id')
            ->distinct('employer_id')
            ->count('employer_id');

        return response()->json([
            'total_jobs'       => $totalJobs,
            'total_seekers'    => $totalSeekers,
            'total_employers'  => $totalEmployers,
            'companies_hiring' => $companiesHiring,
        ]);
    }

}



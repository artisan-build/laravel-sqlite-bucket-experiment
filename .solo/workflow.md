# Workflow: laravel-sqlite-bucket-experiment

**THROWAWAY EXPERIMENT.** A stock Laravel 13 skeleton. The question it answers: *can a Laravel app on
Laravel Cloud keep its SQLite database on the instance and continuously replicate it to an attached Cloud
bucket with Litestream, instead of paying for a managed database?* Idea:
https://scalpels.app/lab/ideas/keep-a-laravel-apps-sqlite-database-in-a-cloud-bucket.
Learnings are harvested into `~/Herd/brain/projects/laravel-sqlite-bucket-experiment/` and an `ideas/` note.
"Not right now" is a valid outcome.

## Phase & mode
- phase: experiment
- default mode: A-autonomous. **Commit directly to `main`** in small commits and push. No PRs (Ed, 2026-10-05).
- The repo is PUBLIC. Nothing secret goes in it, ever.
- Deploy target: the Artisan Build Laravel Cloud org (`org-9e4a1722-9442-404e-abdb-1ca55f845597`). Ed has
  pre-authorised deploys to THIS experiment's own Cloud app(s) and resources. Nothing else in the org may be touched.

## Hard gate
- `php artisan test` for anything you add tests for, plus `vendor/bin/pint --test`.
- Behaviour that matters is LIVE behaviour on Cloud. Tests are secondary in an experiment.

## Agent-role constraints
- none. Fleet bindings come from `~/Herd/brain/agents.json`.

## Hard rules for this repo
- Never set a Cloud env var for a Cloud-provisioned resource (bucket credentials are injected). App secrets may be
  set by hand. Secrets never go on disk or into git.
- `.cloud/config.json` is committed (brain standing policy).

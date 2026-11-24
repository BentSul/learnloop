#!/bin/bash
# Start script for hosting on Railway (Railpack uses this instead of its default Laravel script).
# Settings come from the service's Variables in the Railway dashboard.
set -e

# Use the current Variables, not the ones cached when the image was built.
php artisan config:clear

php artisan migrate --force

# Load the demo courses and accounts on first start only (never touches existing users).
if [ "$LEARNLOOP_SEED_DEMO" = "true" ]; then
  php artisan learnloop:seed-demo
fi

# Link real Canvas courses: LEARNLOOP_CANVAS_COURSES="12345|Web Technologies|CS341,67890|Data Structures|CS213"
# Courses that are already linked are skipped.
if [ -n "$LEARNLOOP_CANVAS_COURSES" ]; then
  IFS=',' read -ra COURSES <<< "$LEARNLOOP_CANVAS_COURSES"
  for entry in "${COURSES[@]}"; do
    IFS='|' read -r course_id course_name course_code <<< "$entry"
    course_id="$(echo "$course_id" | xargs)"
    [ -z "$course_id" ] && continue
    php artisan learnloop:link-course "$course_id" --name="${course_name:-Canvas course $course_id}" --code="$course_code" || true
  done
fi

# Make registered accounts admins: LEARNLOOP_ADMIN_EMAILS="you@ashesi.edu.gh,friend@ashesi.edu.gh"
if [ -n "$LEARNLOOP_ADMIN_EMAILS" ]; then
  php artisan learnloop:make-admin $(echo "$LEARNLOOP_ADMIN_EMAILS" | tr ',' ' ')
fi

php artisan optimize:clear
php artisan optimize

echo "Starting LearnLoop ..."
exec docker-php-entrypoint --config /Caddyfile --adapter caddyfile 2>&1

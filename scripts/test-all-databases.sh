#!/bin/bash
# SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Run tests against all supported databases (SQLite, MySQL, PostgreSQL)
# Uses podman-compose to spin up test environments

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
DOCKER_DIR="$PROJECT_ROOT/tests/docker"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Default settings
DATABASES="${DATABASES:-sqlite mysql pgsql}"
KEEP_RUNNING="${KEEP_RUNNING:-false}"
PULL_IMAGES="${PULL_IMAGES:-true}"
SKIP_BUILD="${SKIP_BUILD:-false}"

usage() {
    echo "Usage: $0 [OPTIONS] [DATABASES...]"
    echo ""
    echo "Run tests against different database backends."
    echo ""
    echo "Options:"
    echo "  -h, --help         Show this help message"
    echo "  -k, --keep         Keep containers running after tests"
    echo "  -s, --skip-pull    Skip pulling images (use existing)"
    echo "  -b, --skip-build   Skip npm build step"
    echo ""
    echo "Databases:"
    echo "  sqlite   Test with SQLite (port 8081)"
    echo "  mysql    Test with MySQL 8.0 (port 8082)"
    echo "  pgsql    Test with PostgreSQL 15 (port 8083)"
    echo ""
    echo "Examples:"
    echo "  $0                    # Test all databases"
    echo "  $0 sqlite mysql       # Test only SQLite and MySQL"
    echo "  $0 -k pgsql           # Test PostgreSQL, keep running"
    echo ""
}

log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Parse arguments
POSITIONAL_ARGS=()
while [[ $# -gt 0 ]]; do
    case $1 in
        -h|--help)
            usage
            exit 0
            ;;
        -k|--keep)
            KEEP_RUNNING=true
            shift
            ;;
        -s|--skip-pull)
            PULL_IMAGES=false
            shift
            ;;
        -b|--skip-build)
            SKIP_BUILD=true
            shift
            ;;
        *)
            POSITIONAL_ARGS+=("$1")
            shift
            ;;
    esac
done

# If databases specified as arguments, use those
if [ ${#POSITIONAL_ARGS[@]} -gt 0 ]; then
    DATABASES="${POSITIONAL_ARGS[*]}"
fi

# Check for podman/podman-compose
check_requirements() {
    if ! command -v podman &> /dev/null; then
        log_error "podman is required but not installed"
        exit 1
    fi

    if ! command -v podman-compose &> /dev/null; then
        log_error "podman-compose is required but not installed"
        log_info "Install with: pip install podman-compose"
        exit 1
    fi
}

# Pull required images
pull_images() {
    log_info "Pulling required container images..."

    podman pull docker.io/library/nextcloud:29 || true

    for db in $DATABASES; do
        case $db in
            mysql)
                podman pull docker.io/library/mysql:8.0 || true
                ;;
            pgsql)
                podman pull docker.io/library/postgres:15-alpine || true
                ;;
        esac
    done

    log_success "Images pulled"
}

# Build frontend
build_frontend() {
    log_info "Building frontend..."
    cd "$PROJECT_ROOT"
    npm run build
    log_success "Frontend built"
}

# Start a test environment
start_environment() {
    local db=$1
    local compose_file="$DOCKER_DIR/docker-compose.${db}.yml"

    if [ ! -f "$compose_file" ]; then
        log_error "Compose file not found: $compose_file"
        return 1
    fi

    log_info "Starting $db environment..."
    cd "$DOCKER_DIR"
    podman-compose -f "docker-compose.${db}.yml" up -d

    # Wait for Nextcloud to be ready
    log_info "Waiting for Nextcloud to be ready..."
    local container_name="files_labels_test_${db}"
    local max_attempts=60
    local attempt=0

    while [ $attempt -lt $max_attempts ]; do
        if podman exec "$container_name" curl -sf http://localhost/status.php > /dev/null 2>&1; then
            log_success "$db environment is ready"
            return 0
        fi
        attempt=$((attempt + 1))
        echo -n "."
        sleep 2
    done

    echo ""
    log_error "Timeout waiting for $db environment"
    return 1
}

# Enable the app
enable_app() {
    local db=$1
    local container_name="files_labels_test_${db}"

    log_info "Enabling files_labels app..."
    podman exec -u www-data "$container_name" php occ app:enable files_labels || true
    log_success "App enabled"
}

# Run tests against an environment
run_tests() {
    local db=$1
    local port

    case $db in
        sqlite) port=8081 ;;
        mysql)  port=8082 ;;
        pgsql)  port=8083 ;;
    esac

    log_info "Running tests against $db (port $port)..."

    cd "$PROJECT_ROOT"

    # Run E2E tests with the appropriate base URL
    PLAYWRIGHT_BASE_URL="http://localhost:$port" npx playwright test --reporter=list

    local result=$?
    if [ $result -eq 0 ]; then
        log_success "Tests passed for $db"
    else
        log_error "Tests failed for $db"
    fi

    return $result
}

# Stop an environment
stop_environment() {
    local db=$1

    log_info "Stopping $db environment..."
    cd "$DOCKER_DIR"
    podman-compose -f "docker-compose.${db}.yml" down -v
    log_success "$db environment stopped"
}

# Cleanup all environments
cleanup_all() {
    log_info "Cleaning up all test environments..."
    cd "$DOCKER_DIR"

    for db in sqlite mysql pgsql; do
        if [ -f "docker-compose.${db}.yml" ]; then
            podman-compose -f "docker-compose.${db}.yml" down -v 2>/dev/null || true
        fi
    done

    log_success "Cleanup complete"
}

# Main execution
main() {
    check_requirements

    echo ""
    echo "========================================"
    echo "  File Labels - Multi-Database Tests"
    echo "========================================"
    echo ""
    echo "Databases: $DATABASES"
    echo ""

    # Build frontend if not skipped
    if [ "$SKIP_BUILD" != "true" ]; then
        build_frontend
    fi

    # Pull images if not skipped
    if [ "$PULL_IMAGES" = "true" ]; then
        pull_images
    fi

    # Track results
    declare -A results
    local failed=0

    # Run tests for each database
    for db in $DATABASES; do
        echo ""
        echo "----------------------------------------"
        echo "  Testing with: $db"
        echo "----------------------------------------"
        echo ""

        if start_environment "$db"; then
            enable_app "$db"

            if run_tests "$db"; then
                results[$db]="PASSED"
            else
                results[$db]="FAILED"
                failed=$((failed + 1))
            fi
        else
            results[$db]="ERROR"
            failed=$((failed + 1))
        fi

        # Stop environment unless --keep was specified
        if [ "$KEEP_RUNNING" != "true" ]; then
            stop_environment "$db"
        fi
    done

    # Print summary
    echo ""
    echo "========================================"
    echo "  Test Summary"
    echo "========================================"
    echo ""

    for db in $DATABASES; do
        local status="${results[$db]}"
        case $status in
            PASSED)
                echo -e "  $db: ${GREEN}$status${NC}"
                ;;
            FAILED)
                echo -e "  $db: ${RED}$status${NC}"
                ;;
            ERROR)
                echo -e "  $db: ${YELLOW}$status${NC}"
                ;;
        esac
    done

    echo ""

    if [ $failed -eq 0 ]; then
        log_success "All tests passed!"
        exit 0
    else
        log_error "$failed database(s) failed"
        exit 1
    fi
}

# Only cleanup on unexpected exit if not keeping containers
if [ "$KEEP_RUNNING" != "true" ]; then
    trap 'echo ""; log_warn "Interrupted, cleaning up..."; cleanup_all; exit 1' INT TERM
fi

main

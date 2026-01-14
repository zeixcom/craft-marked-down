#!/bin/bash

# Marked Down Cache Testing Script
# Tests if caching is working by comparing response times

set -e

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Default values
URL="${1:-http://localhost:8000/}"
CLEAR_CACHE="${2:-yes}"
NUM_REQUESTS="${3:-3}"

echo -e "${BLUE}Marked Down Cache Test${NC}"
echo -e "${BLUE}======================${NC}"
echo ""
echo "URL: $URL"
echo "Number of requests: $NUM_REQUESTS"
echo ""

# Function to make a request and measure time
make_request() {
    local request_num=$1
    local start_time=$(date +%s.%N)
    curl -s -H "Accept: text/markdown" "$URL" > /dev/null
    local end_time=$(date +%s.%N)
    local duration=$(echo "$end_time - $start_time" | bc)
    echo "$duration"
}

# Function to format time
format_time() {
    printf "%.3f" "$1"
}

# Clear cache if requested
if [ "$CLEAR_CACHE" = "yes" ]; then
    echo -e "${YELLOW}Clearing Craft cache...${NC}"
    if command -v ./craft &> /dev/null; then
        ./craft clear-caches/all 2>/dev/null || echo "  (Note: Clear cache command may have failed, but continuing anyway)"
    else
        echo "  (craft command not found, skipping cache clear)"
    fi
    echo ""
    sleep 1
fi

echo -e "${BLUE}Making $NUM_REQUESTS requests with Accept: text/markdown header...${NC}"
echo ""

times=()
for i in $(seq 1 $NUM_REQUESTS); do
    echo -n "Request $i: "
    duration=$(make_request $i)
    times+=($duration)
    echo -e "${GREEN}$(format_time $duration)s${NC}"
    sleep 0.5
done

echo ""
echo -e "${BLUE}Results:${NC}"
echo "-------"

# Calculate statistics
first_time=${times[0]}
avg_time=0
min_time=${times[0]}
max_time=${times[0]}

for time in "${times[@]}"; do
    avg_time=$(echo "$avg_time + $time" | bc)
    if (( $(echo "$time < $min_time" | bc -l) )); then
        min_time=$time
    fi
    if (( $(echo "$time > $max_time" | bc -l) )); then
        max_time=$time
    fi
done

avg_time=$(echo "scale=3; $avg_time / $NUM_REQUESTS" | bc)

echo "First request:  $(format_time $first_time)s"
echo "Average time:   $(format_time $avg_time)s"
echo "Minimum time:   $(format_time $min_time)s"
echo "Maximum time:   $(format_time $max_time)s"
echo ""

# Determine if cache is working
# If subsequent requests are significantly faster, cache is working
if [ ${#times[@]} -ge 2 ]; then
    second_time=${times[1]}
    speedup=$(echo "scale=2; $first_time / $second_time" | bc)
    
    echo -e "${BLUE}Cache Analysis:${NC}"
    echo "First request:  $(format_time $first_time)s"
    echo "Second request: $(format_time $second_time)s"
    echo "Speedup:        ${speedup}x"
    echo ""
    
    # Cache is likely working if second request is at least 20% faster
    threshold=$(echo "$first_time * 0.8" | bc)
    if (( $(echo "$second_time < $threshold" | bc -l) )); then
        echo -e "${GREEN}✓ Cache appears to be WORKING${NC}"
        echo -e "${GREEN}  Second request is significantly faster than the first${NC}"
    else
        echo -e "${RED}✗ Cache may NOT be working${NC}"
        echo -e "${YELLOW}  Second request is not significantly faster${NC}"
        echo -e "${YELLOW}  (First: $(format_time $first_time)s, Second: $(format_time $second_time)s)${NC}"
        echo ""
        echo "Possible reasons:"
        echo "  - Caching is disabled in plugin settings"
        echo "  - Cache backend is not working properly"
        echo "  - Response is too fast to measure difference"
        echo "  - Content changed between requests"
    fi
else
    echo -e "${YELLOW}Need at least 2 requests to test caching${NC}"
fi

echo ""

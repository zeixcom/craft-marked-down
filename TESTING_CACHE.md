# Testing Cache Functionality

## Quick Test Methods

### Method 1: Response Time Comparison

1. **Clear cache first:**
   ```bash
   ./craft clear-caches/all
   ```

2. **First request (should be slower - cache miss):**
   ```bash
   time curl -H "Accept: text/markdown" https://yoursite.com/
   ```
   Note the time (e.g., 0.5 seconds)

3. **Second request (should be faster - cache hit):**
   ```bash
   time curl -H "Accept: text/markdown" https://yoursite.com/
   ```
   Should be significantly faster (e.g., 0.1 seconds)

### Method 2: Check Cache Keys (if using file cache)

If Craft is using file-based cache, check the cache directory:

```bash
# Find cache files (adjust path as needed)
find storage/runtime/cache -name "*marked-down*" -type f

# Or check the cache directory structure
ls -la storage/runtime/cache/data/
```

### Method 3: Temporary Logging (Developer Method)

Add temporary logging to see cache hits/misses:

In `MarkdownService.php`, temporarily add:

```php
// After line 50 (cache get)
if ($cached !== false) {
    Craft::info('Marked Down: Cache HIT for key: ' . $cacheKey, __METHOD__);
    return $cached;
}

// Before line 64 (cache set)
Craft::info('Marked Down: Cache MISS - generating and caching for key: ' . $cacheKey, __METHOD__);
```

Then check logs:
```bash
tail -f storage/logs/web.log | grep "Marked Down"
```

### Method 4: Disable Cache and Compare

1. **With cache enabled:**
   - Make multiple requests
   - Should be fast after first request

2. **Disable cache in plugin settings:**
   - Settings → Plugins → Marked Down
   - Turn off "Enable Caching"
   - Make requests - all should be same speed (no cache benefit)

3. **Re-enable cache:**
   - First request slow, subsequent fast again

### Method 5: Cache Duration Test

1. **Set very short cache duration** (e.g., 10 seconds) in plugin settings
2. **Make a request** - should cache
3. **Make another request immediately** - should use cache (fast)
4. **Wait 15 seconds**
5. **Make another request** - cache expired, should regenerate (slower)

## Expected Behavior

✅ **Cache Working:**
- First request: Slower (generating Markdown)
- Subsequent requests: Faster (served from cache)
- After cache expires: Slower again (regenerating)

❌ **Cache Not Working:**
- All requests: Same speed
- No cache files/keys created
- Logs show no cache hits

## Troubleshooting

- **Cache not working?** Check plugin settings - ensure "Enable Caching" is on
- **All requests slow?** Check if cache backend is working (Redis/Memcached connection, file permissions)
- **Cache not expiring?** Verify cache duration setting is correct

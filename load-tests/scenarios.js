import http from 'k6/http';
import { check, fail, sleep } from 'k6';
import { Counter, Rate } from 'k6/metrics';

// Treat every response outside HTTP 2xx as failed, including redirects and 429.
http.setResponseCallback(http.expectedStatuses({ min: 200, max: 299 }));

const profiles = {
  smoke: [{ duration: '10s', target: 2 }, { duration: '20s', target: 2 }, { duration: '5s', target: 0 }],
  load: [{ duration: '1m', target: 10 }, { duration: '2m', target: 30 }, { duration: '1m', target: 50 }, { duration: '30s', target: 0 }],
  stress: [{ duration: '1m', target: 25 }, { duration: '2m', target: 75 }, { duration: '2m', target: 150 }, { duration: '2m', target: 250 }, { duration: '1m', target: 0 }],
  spike: [{ duration: '20s', target: 15 }, { duration: '15s', target: 200 }, { duration: '40s', target: 200 }, { duration: '20s', target: 0 }],
  soak: [{ duration: '1m', target: 20 }, { duration: '20m', target: 20 }, { duration: '1m', target: 0 }]
};

const profile = __ENV.SYMSVEL_LOAD_PROFILE || 'smoke';
if (!Object.prototype.hasOwnProperty.call(profiles, profile)) {
  fail('Unknown test profile. Allowed: smoke, load, stress, spike, soak.');
}

const base = __ENV.SYMSVEL_LOAD_URL || '';
const parsed = /^https?:\/\/([^/:?#]+|\[[^\]]+\])(?::([0-9]{1,5}))?\/?$/.exec(base);
if (!parsed || !base || /@/.test(base)) {
  fail('Target must be a single explicit HTTP(S) origin without credentials, path, query or fragment.');
}
const host = parsed[1].toLowerCase();
const isLoopback = ['localhost', '127.0.0.1', '[::1]'].includes(host);
if (!isLoopback && __ENV.SYMSVEL_ALLOW_NON_LOOPBACK !== '1') {
  fail('Non-loopback host refused. Explicit authorization is required.');
}
if (parsed[2] && (+parsed[2] < 1 || +parsed[2] > 65535)) {
  fail('Invalid TCP port.');
}

const paths = (__ENV.SYMSVEL_LOAD_PATHS || '/')
  .split(',')
  .map((p) => p.trim())
  .filter((p) => p.length > 0);
if (paths.length < 1 || paths.length > 12 || new Set(paths).size !== paths.length) {
  fail('Provide 1 to 12 distinct read-only relative paths.');
}
for (const path of paths) {
  if (!path.startsWith('/') || path.startsWith('//') || /[?#\\\s]/.test(path) ||
      /%2f|%5c|\.\./i.test(path) || !/^\/[A-Za-z0-9/_\-.]*$/.test(path)) {
    fail('Unsafe path in SYMSVEL_LOAD_PATHS; only literal public GET paths are accepted.');
  }
}

const target = base.replace(/\/$/, '');
const serverErrors = new Rate('symsvel_server_error_rate');
const throttled = new Counter('symsvel_http_429');
const requests = new Counter('symsvel_requests');

export const options = {
  discardResponseBodies: true,
  noConnectionReuse: false,
  scenarios: {
    [profile]: {
      executor: 'ramping-vus',
      stages: profiles[profile],
      gracefulRampDown: '10s',
      gracefulStop: '10s'
    }
  },
  thresholds: {
    http_req_failed: [{ threshold: 'rate<0.01', abortOnFail: false }],
    http_req_duration: ['p(95)<800', 'p(99)<2000'],
    symsvel_server_error_rate: ['rate<0.01']
  },
  tags: { project: __ENV.SYMSVEL_LOAD_LABEL || 'symSvel-local', profile }
};

export default function () {
  // Stable deterministic distribution; each VU visits every selected route.
  for (const path of paths) {
    const response = http.get(target + path, {
      redirects: 0,
      tags: { name: path },
      timeout: '10s'
    });
    requests.add(1, { path });
    serverErrors.add(response.status === 0 || response.status >= 500, { path });
    if (response.status === 429) throttled.add(1, { path });
    check(response, {
      'HTTP response is not 5xx': (r) => r.status > 0 && r.status < 500,
      'public endpoint is 2xx': (r) => r.status >= 200 && r.status < 300
    }, { path });
  }
  sleep(0.3);
}

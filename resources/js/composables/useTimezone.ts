/**
 * 文件说明：前端组合式逻辑，封装页面间复用的状态和浏览器侧行为。
 */
import { getTimeZones, timeZonesNames } from '@vvo/tzdb';
import { onMounted, ref } from 'vue';

export type Timezone = string;

type TimezoneLocale = 'zh-CN' | 'en';

type TimezoneOption = {
  value: string;
  label: string;
  offset: string;
};

const allTimeZones = getTimeZones({ includeUtc: true });
const validTimeZoneSet = new Set([
  ...timeZonesNames,
  ...allTimeZones.map((timezone) => timezone.name),
]);

const formatOffsetFromMinutes = (minutes: number) => {
  const sign = minutes >= 0 ? '+' : '-';
  const abs = Math.abs(minutes);
  const hh = String(Math.floor(abs / 60)).padStart(2, '0');
  const mm = String(abs % 60).padStart(2, '0');
  return `UTC${sign}${hh}:${mm}`;
};

const getContinentKey = (tz: string) => tz.split('/')[0] || '';

const getLocalizedTimeZoneName = (tz: string, locale: TimezoneLocale) => {
  const dtf = new Intl.DateTimeFormat(locale, {
    timeZone: tz,
    timeZoneName: 'long',
  });
  const parts = dtf.formatToParts(new Date());
  const tzName = parts.find((p) => p.type === 'timeZoneName')?.value;
  if (tzName) return tzName;

  const fromDb = allTimeZones.find((z) => z.name === tz);
  if (fromDb?.alternativeName) return fromDb.alternativeName;
  return tz.split('/').slice(-1)[0]?.replace(/_/g, ' ') || tz;
};

// 生成时区数据（无硬编码城市/翻译；label 在 getTimezones 里根据 locale 生成）
export const timezoneData = allTimeZones.map((z) => ({
  value: z.name,
  offsetMinutes: z.currentTimeOffsetInMinutes,
  offset: formatOffsetFromMinutes(z.currentTimeOffsetInMinutes),
  continent: z.continentName,
  continentCode: z.continentCode,
  countryName: z.countryName,
  countryCode: z.countryCode,
  alternativeName: z.alternativeName,
  mainCities: z.mainCities,
}));

const getStoredTimezone = (): Timezone | null => {
  if (typeof window === 'undefined') {
    return null;
  }

  return localStorage.getItem('timezone') as Timezone | null;
};

// 获取浏览器默认时区
const getBrowserTimezone = (): Timezone => {
  if (typeof window === 'undefined') {
    return 'UTC';
  }

  const browserTz = Intl.DateTimeFormat().resolvedOptions().timeZone;

  // 检查浏览器时区是否是有效 IANA 标识（兼容 deprecated）
  if (validTimeZoneSet.has(browserTz)) return browserTz;

  throw new Error(`Unsupported browser timezone: ${browserTz}`);
};

const timezone = ref<Timezone>(getBrowserTimezone());

export function initializeTimezone(userTimezone?: string | null) {
  if (typeof window === 'undefined') {
    return;
  }

  if (userTimezone) {
    if (!validTimeZoneSet.has(userTimezone)) {
      throw new Error(`Unsupported user timezone: ${userTimezone}`);
    }

    timezone.value = userTimezone;
    localStorage.setItem('timezone', userTimezone);
    return;
  }

  const savedTimezone = getStoredTimezone();
  if (savedTimezone && validTimeZoneSet.has(savedTimezone)) {
    timezone.value = savedTimezone;
  }
}

export function useTimezone() {
  onMounted(() => {
    const savedTimezone = getStoredTimezone();

    if (savedTimezone && validTimeZoneSet.has(savedTimezone)) {
      timezone.value = savedTimezone;
    }
  });

  function updateTimezone(newTimezone: Timezone) {
    if (!validTimeZoneSet.has(newTimezone)) {
      throw new Error(`Unsupported timezone: ${newTimezone}`);
    }
    timezone.value = newTimezone;

    // 保存到本地，供下次打开页面时恢复。
    localStorage.setItem('timezone', newTimezone);
  }

  const getOffsetMinutes = (tz: string) => {
    const data = timezoneData.find((x) => x.value === tz);
    if (!data) {
      throw new Error(`Unsupported timezone: ${tz}`);
    }

    return data.offsetMinutes;
  };

  // “常用优先”：当前/浏览器/同大洲/offset 接近
  function getTimezones(lang: TimezoneLocale): TimezoneOption[] {
    const browser = getBrowserTimezone();
    const current = timezone.value;
    const currentContinent = getContinentKey(current);
    const browserContinent = getContinentKey(browser);
    const currentOffset = getOffsetMinutes(current);

    const scored = timezoneData.map((z, idx) => {
      const continent = getContinentKey(z.value);
      const offsetDiff = Math.abs(z.offsetMinutes - currentOffset);
      let score = 100;

      if (z.value === current) score = 0;
      else if (z.value === browser) score = 1;
      else if (continent && continent === currentContinent) score = 2;
      else if (continent && continent === browserContinent) score = 3;
      else if (offsetDiff <= 60) score = 4;
      else if (offsetDiff <= 120) score = 5;
      else score = 10;

      return {
        idx,
        score,
        offsetDiff,
        value: z.value,
        offset: z.offset,
      };
    });

    scored.sort((a, b) => {
      if (a.score !== b.score) return a.score - b.score;
      if (a.offsetDiff !== b.offsetDiff) return a.offsetDiff - b.offsetDiff;
      return a.idx - b.idx; // 保持 tzdb 原本排序的稳定性
    });

    return scored.map((x) => ({
      value: x.value,
      offset: x.offset,
      label: `${getLocalizedTimeZoneName(x.value, lang)} ${x.offset}`,
    }));
  }

  // 获取当前时区信息
  function getCurrentTimezoneInfo(lang: TimezoneLocale) {
    const data = timezoneData.find((tz) => tz.value === timezone.value);
    if (!data) {
      throw new Error(`Unsupported timezone: ${timezone.value}`);
    }

    return {
      value: data.value,
      label: `${getLocalizedTimeZoneName(timezone.value, lang)} ${data.offset}`,
      offset: data.offset,
    };
  }

  return {
    timezone,
    timezoneData,
    updateTimezone,
    getTimezones,
    getCurrentTimezoneInfo,
  };
}

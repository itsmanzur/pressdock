import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import en from './en.json';
import bn from './bn.json';

export type Lang = 'en' | 'bn';

export const detectLang = (): Lang =>
  typeof navigator !== 'undefined' && navigator.language?.toLowerCase().startsWith('bn') ? 'bn' : 'en';

void i18n.use(initReactI18next).init({
  resources: { en: { translation: en }, bn: { translation: bn } },
  lng: detectLang(),
  fallbackLng: 'en',
  interpolation: { escapeValue: false },
  returnNull: false,
});

export default i18n;

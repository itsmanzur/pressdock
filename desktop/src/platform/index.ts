import type { Platform } from './types';
import { tauriPlatform } from './tauri';
import { webPlatform } from './web';

export const isTauri = typeof window !== 'undefined' && '__TAURI_INTERNALS__' in window;
export const platform: Platform = isTauri ? tauriPlatform : webPlatform;
export type { Platform, SqlDb, SqlValue, Stmt } from './types';

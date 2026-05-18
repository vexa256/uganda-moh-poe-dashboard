/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_SERVER_URL?: string
  readonly VITE_COUNTRY_CODE?: 'ZM' | 'UG' | 'RW' | string
}

interface Window {
  SERVER_URL: string
  COUNTRY_CODE: string
}

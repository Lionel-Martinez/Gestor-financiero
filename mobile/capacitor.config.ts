import type { CapacitorConfig } from '@capacitor/cli'

const config: CapacitorConfig = {
  appId: 'ar.com.lmstudio.gestorfinanciero',
  appName: 'Gestor Financiero',
  webDir: 'dist',
  plugins: {
    CapacitorSQLite: {
      // Base local sin cifrar en V1. Endurecimiento previsto: SQLCipher (ver docs/04-seguridad-y-despliegue).
      androidIsEncryption: false,
      iosIsEncryption: false,
    },
  },
}

export default config

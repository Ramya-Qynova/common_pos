import './App.css';
import { t } from './i18n';

function App() {
  return (
    <main className="boot-screen">
      <h1>{t('app.boot.title')}</h1>
      <p>{t('app.boot.subtitle')}</p>
    </main>
  );
}

export default App;

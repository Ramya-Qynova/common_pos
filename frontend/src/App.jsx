import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import './App.css';
import { AuthProvider, useAuth } from './auth/AuthContext';
import LoginScreen from './screens/LoginScreen';
import AppShell from './screens/AppShell';
import PosScreen from './screens/PosScreen';

function Root() {
  const { session } = useAuth();

  if (!session) {
    return <LoginScreen />;
  }

  return (
    <BrowserRouter>
      <Routes>
        <Route path="/" element={<AppShell />}>
          <Route index element={<Navigate to="/pos" replace />} />
          <Route path="pos" element={<PosScreen />} />
          <Route path="*" element={<Navigate to="/pos" replace />} />
        </Route>
      </Routes>
    </BrowserRouter>
  );
}

function App() {
  return (
    <AuthProvider>
      <Root />
    </AuthProvider>
  );
}

export default App;

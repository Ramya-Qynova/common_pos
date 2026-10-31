import './App.css';
import { AuthProvider, useAuth } from './auth/AuthContext';
import LoginScreen from './screens/LoginScreen';
import AppShell from './screens/AppShell';

function Root() {
  const { session } = useAuth();

  return session ? <AppShell /> : <LoginScreen />;
}

function App() {
  return (
    <AuthProvider>
      <Root />
    </AuthProvider>
  );
}

export default App;

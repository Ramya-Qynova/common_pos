import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import './App.css';
import { AuthProvider, useAuth } from './auth/AuthContext';
import LoginScreen from './screens/LoginScreen';
import AppShell from './screens/AppShell';
import PosScreen from './screens/PosScreen';
import { SalesScreen } from './screens/SalesScreen';
import { ProductsScreen } from './screens/ProductsScreen';
import { InventoryScreen } from './screens/InventoryScreen';
import { CustomersScreen } from './screens/CustomersScreen';
import { ReportsScreen } from './screens/ReportsScreen';
import { TransfersScreen } from './screens/TransfersScreen';
import { ReconciliationsScreen } from './screens/ReconciliationsScreen';
import { TemplatesScreen } from './screens/TemplatesScreen';

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
          <Route path="sales" element={<SalesScreen />} />
          <Route path="catalog" element={<ProductsScreen />} />
          <Route path="products" element={<ProductsScreen />} />
          <Route path="inventory" element={<InventoryScreen />} />
          <Route path="customers" element={<CustomersScreen />} />
          <Route path="reports" element={<ReportsScreen />} />
          <Route path="transfers" element={<TransfersScreen />} />
          <Route path="reconciliations" element={<ReconciliationsScreen />} />
          <Route path="templates" element={<TemplatesScreen />} />
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
